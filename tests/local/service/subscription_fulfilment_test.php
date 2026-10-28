<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace paygw_stripe\local\service;

defined('MOODLE_INTERNAL') || die();

use paygw_stripe\local\repository\subscription_repository;
use paygw_stripe\payment\service_provider;
use paygw_stripe\stripe_helper;
use paygw_stripe\tests\fixtures\stripe_testcase;
use paygw_stripe\tests\fixtures\subscription_lock_factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\ApiRequestor;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\HttpClient\ClientInterface;
use Stripe\Service\Checkout\CheckoutServiceFactory;
use Stripe\Service\Checkout\SessionService;
use Stripe\Service\SubscriptionService;
use Stripe\Subscription;

require_once(__DIR__ . '/../../fixtures/stripe_testcase.php');
require_once(__DIR__ . '/../../fixtures/subscription_test_session_factory.php');
require_once(__DIR__ . '/../../fixtures/subscription_payment_provider.php');
require_once(__DIR__ . '/../../fixtures/subscription_lock_factory.php');

/**
 * Initial subscription delivery and replay protection.
 *
 * @package paygw_stripe
 * @category test
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(subscription_service::class)]
#[CoversClass(stripe_helper::class)]
final class subscription_fulfilment_test extends stripe_testcase {
    /** @var subscription_service Service under test. */
    private subscription_service $service;
    /** @var Session Stripe Checkout fixture. */
    private Session $session;
    /** @var Subscription Stripe subscription fixture. */
    private Subscription $subscription;
    /** @var int Purchasing user. */
    private int $userid;
    /** @var int Fee enrolment instance. */
    private int $instanceid;

    /**
     * Create an eligible Checkout with real fee enrolment callbacks.
     */
    protected function setUp(): void {
        global $CFG, $DB, $USER;

        parent::setUp();
        $CFG->lock_factory = subscription_lock_factory::class;
        $this->setUser($this->getDataGenerator()->create_user());
        $this->userid = (int)$USER->id;
        $account = $this->getDataGenerator()->get_plugin_generator('core_payment')
            ->create_payment_account(['gateways' => 'stripe']);
        $course = $this->getDataGenerator()->create_course();
        $this->instanceid = enrol_get_plugin('fee')->add_instance($course, [
            'customint1' => $account->get('id'),
            'cost' => 25,
            'currency' => 'USD',
            'enrolperiod' => WEEKSECS,
            'roleid' => $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST),
        ]);
        $DB->insert_record('paygw_stripe_products', (object)[
            'productid' => 'prod_fulfil',
            'component' => 'enrol_fee',
            'paymentarea' => 'fee',
            'itemid' => $this->instanceid,
        ]);
        $this->session = subscription_test_session_factory::subscription_session(
            'cs_fulfil',
            'sub_fulfil',
            'cus_fulfil',
            'prod_fulfil',
            'price_fulfil'
        );
        $this->session->metadata = ['userid' => $this->userid];
        $this->session->status = 'complete';
        $this->session->payment_status = 'paid';
        $this->subscription = Subscription::constructFrom(['id' => 'sub_fulfil', 'status' => 'active']);
        $checkout = $this->mock_stripe_service('checkout', CheckoutServiceFactory::class);
        $sessions = $this->createMock(SessionService::class);
        $checkout->method('__get')->with('sessions')->willReturn($sessions);
        $sessions->method('retrieve')->willReturnCallback(fn() => $this->session);
        $subscriptions = $this->mock_stripe_service('subscriptions', SubscriptionService::class);
        $subscriptions->method('retrieve')->willReturnCallback(fn() => $this->subscription);
        $this->service = new subscription_service($this->client);
    }

    /**
     * Clear static fixture controls.
     */
    protected function tearDown(): void {
        service_provider::$failure = '';
        subscription_lock_factory::$beforelock = null;
        parent::tearDown();
    }

    /**
     * A replay cannot record another payment or change enrolment expiry.
     */
    public function test_replay_does_not_redeliver(): void {
        global $DB;

        $this->assertTrue($this->fulfil());
        $enrolment = $DB->get_record('user_enrolments', ['enrolid' => $this->instanceid], '*', MUST_EXIST);
        $DB->set_field('user_enrolments', 'timeend', $enrolment->timeend - DAYSECS, ['id' => $enrolment->id]);
        $this->assertFalse($this->fulfil());
        // Even a different Checkout session for the same subscription cannot replay delivery.
        $this->session = Session::constructFrom(array_replace($this->session->toArray(), ['id' => 'cs_other_return']));
        $this->assertFalse($this->fulfil());
        $this->assertSame(1, $DB->count_records('payments'));
        $this->assertEquals($enrolment->timeend - DAYSECS, $DB->get_field('user_enrolments', 'timeend', [
            'id' => $enrolment->id,
        ]));
        $record = (new subscription_repository())->find_by_subscriptionid('sub_fulfil');
        $this->assertTrue($record->delivered);
        $this->assertTrue($record->with_status('past_due')->delivered);
    }

    /**
     * Ineligible Stripe states.
     *
     * @return array
     */
    public static function ineligible_provider(): array {
        return [
            'past due' => ['past_due', 'paid', 'complete'],
            'unpaid subscription' => ['unpaid', 'paid', 'complete'],
            'paused' => ['paused', 'paid', 'complete'],
            'incomplete' => ['incomplete', 'paid', 'complete'],
            'expired subscription' => ['incomplete_expired', 'paid', 'complete'],
            'canceled' => ['canceled', 'paid', 'complete'],
            'unpaid Checkout' => ['active', 'unpaid', 'complete'],
            'open Checkout' => ['active', 'paid', 'open'],
            'expired Checkout' => ['active', 'paid', 'expired'],
        ];
    }

    /**
     * Unpaid or incomplete purchases cannot grant initial access.
     *
     * @param string $status Subscription status
     * @param string $paymentstatus Checkout payment status
     * @param string $checkoutstatus Checkout status
     */
    #[DataProvider('ineligible_provider')]
    public function test_rejects_ineligible_states(string $status, string $paymentstatus, string $checkoutstatus): void {
        global $DB;

        $this->subscription->status = $status;
        $this->session->payment_status = $paymentstatus;
        $this->session->status = $checkoutstatus;
        $this->assert_error('subscriptionerror', fn() => $this->fulfil());
        $this->assertSame(0, $DB->count_records('payments'));
        $this->assertSame(0, $DB->count_records('user_enrolments', ['enrolid' => $this->instanceid]));
        $this->assertSame(0, $DB->count_records('paygw_stripe_subscriptions'));
    }

    /**
     * Legitimate trial and free-first-interval states.
     *
     * @return array
     */
    public static function free_period_provider(): array {
        return ['trial' => ['trialing'], 'free anchored interval' => ['active']];
    }

    /**
     * Free initial periods still grant access exactly once.
     *
     * @param string $status Subscription status
     */
    #[DataProvider('free_period_provider')]
    public function test_free_period_is_delivered_once(string $status): void {
        global $DB;

        $this->subscription->status = $status;
        $this->session->payment_status = 'no_payment_required';
        $this->assertTrue($this->fulfil());
        $this->assertFalse($this->fulfil());
        $this->assertSame(1, $DB->count_records('payments'));
    }

    /**
     * Original user/product binding still applies.
     */
    public function test_rejects_foreign_user_and_product(): void {
        $this->assert_error('invalidsessionbinding', fn() => $this->service->fulfil_subscription(
            'cs_fulfil',
            $this->userid + 1,
            'enrol_fee',
            'fee',
            $this->instanceid
        ));
        $this->assert_error('invalidsessionbinding', fn() => $this->service->fulfil_subscription(
            'cs_fulfil',
            $this->userid,
            'enrol_fee',
            'fee',
            $this->instanceid + 1
        ));
    }

    /**
     * Failures after enrolment must roll back both payment and access.
     *
     * @return array
     */
    public static function failure_provider(): array {
        return ['false callback' => ['false'], 'throwing callback' => ['exception']];
    }

    /**
     * A failed callback leaves a safely retryable purchase.
     *
     * @param string $failure Callback failure mode
     */
    #[DataProvider('failure_provider')]
    public function test_delivery_failure_rolls_back(string $failure): void {
        global $DB;

        $DB->set_field('paygw_stripe_products', 'component', 'paygw_stripe', ['productid' => 'prod_fulfil']);
        service_provider::$failure = $failure;
        $this->preventResetByRollback();
        $this->assert_error('subscriptiondeliveryfailed', fn() => $this->fulfil('paygw_stripe'));
        $this->assertSame(0, $DB->count_records('payments'));
        $this->assertSame(0, $DB->count_records('user_enrolments', ['enrolid' => $this->instanceid]));
        $this->assertSame(0, $DB->count_records('paygw_stripe_subscriptions'));
        service_provider::$failure = '';
        $this->assertTrue($this->fulfil('paygw_stripe'));
        $this->assertFalse($this->fulfil('paygw_stripe'));
        $this->assertSame(1, $DB->count_records('payments'));
    }

    /**
     * A second request cannot deliver while the subscription lock is held.
     */
    public function test_lock_contention_prevents_delivery(): void {
        global $DB;

        $factory = \core\lock\lock_config::get_lock_factory('paygw_stripe');
        $lock = $factory->get_lock('subscription_' . hash('sha256', 'sub_fulfil'), 0);
        $this->assertNotFalse($lock);
        try {
            $this->assert_error('locktimeout', fn() => $this->fulfil());
            $this->assertSame(0, $DB->count_records('payments'));
        } finally {
            $lock->release();
        }
        $this->assertTrue($this->fulfil());
    }

    /**
     * A request waiting for the lock must re-read another request's delivery marker.
     */
    public function test_delivery_is_checked_after_lock_acquisition(): void {
        global $DB;

        subscription_lock_factory::$beforelock = function (): void {
            $this->assertTrue($this->fulfil());
        };
        $this->assertFalse($this->fulfil());
        $this->assertSame(1, $DB->count_records('payments'));
    }

    /**
     * Possible arrival orders of browser return and asynchronous notification.
     *
     * @return array
     */
    public static function arrival_order_provider(): array {
        return ['browser first' => [true], 'webhook first' => [false]];
    }

    /**
     * Async notification and browser return share the same delivery guard.
     *
     * @param bool $browserfirst Whether the browser delivers first
     */
    #[DataProvider('arrival_order_provider')]
    public function test_webhook_and_browser_share_delivery_state(bool $browserfirst): void {
        global $DB;

        if ($browserfirst) {
            $this->assertTrue($this->fulfil());
        }
        $helper = new stripe_helper('pk_test', 'sk_test');
        foreach (['stripe' => $this->client, 'subscriptionservice' => $this->service] as $name => $value) {
            (new \ReflectionProperty(stripe_helper::class, $name))->setValue($helper, $value);
        }
        $event = Event::constructFrom([
            'id' => 'evt_subscription_paid',
            'type' => 'checkout.session.async_payment_succeeded',
            'data' => ['object' => ['id' => 'cs_fulfil']],
        ]);
        $metadata = ['component' => 'enrol_fee', 'paymentarea' => 'fee', 'itemid' => $this->instanceid];
        // Webhooks run without an authenticated Moodle user.
        $this->setUser(null);
        $messages = $this->redirectMessages();
        $this->assertTrue($helper->process_stripe_event($event, $metadata));
        $this->assertFalse($this->fulfil());
        $this->assertTrue($helper->process_stripe_event($event, $metadata));
        $this->assertSame(1, $DB->count_records('payments'));
        $this->assertCount($browserfirst ? 0 : 1, $messages->get_messages());
        $this->assertSame($this->userid, (new subscription_repository())->find_by_subscriptionid('sub_fulfil')->userid);
    }

    /**
     * Status-only webhook saves must not reset a delivery marker held in a stale model.
     */
    public function test_status_updates_preserve_delivery(): void {
        $this->service->save_subscription($this->session);
        $stale = (new subscription_repository())->find_by_subscriptionid('sub_fulfil');
        $this->assertTrue($this->fulfil());
        $this->subscription->status = 'past_due';
        $this->service->sync_status($stale);
        $this->service->save_subscription($this->session);
        $this->assertFalse($this->fulfil());
        $this->assertTrue((new subscription_repository())->find_by_subscriptionid('sub_fulfil')->delivered);
    }

    /**
     * The browser endpoint uses the same atomic guard on repeated success URLs.
     */
    public function test_process_endpoint_cannot_replay_delivery(): void {
        global $CFG, $DB, $USER, $PAGE, $OUTPUT;

        $accountid = $DB->get_field('enrol', 'customint1', ['id' => $this->instanceid], MUST_EXIST);
        $DB->set_field('payment_gateways', 'config', json_encode([
            'apikey' => 'pk_test_fake', 'secretkey' => 'sk_test_fake',
        ]), ['accountid' => $accountid, 'gateway' => 'stripe']);
        $http = $this->createMock(ClientInterface::class);
        $http->method('request')->willReturnCallback(function ($method, $url): array {
            $this->assertSame('get', $method);
            if ($url === 'https://api.stripe.com/v1/checkout/sessions/cs_fulfil') {
                return [json_encode($this->session->toArray()), 200, []];
            }
            $this->assertSame('https://api.stripe.com/v1/subscriptions/sub_fulfil', $url);
            return [json_encode($this->subscription->toArray()), 200, []];
        });
        $original = (new \ReflectionProperty(ApiRequestor::class, '_httpClient'))->getValue();
        $get = $_GET;
        $post = $_POST;
        ApiRequestor::setHttpClient($http);
        $_GET = [
            'component' => 'enrol_fee', 'paymentarea' => 'fee',
            'itemid' => $this->instanceid, 'session_id' => 'cs_fulfil',
        ];
        $_POST = [];
        try {
            $this->assert_error('redirecterrordetected', function () use ($CFG): void {
                global $USER, $PAGE, $OUTPUT;
                require($CFG->dirroot . '/payment/gateway/stripe/process.php');
            });
            $enrolment = $DB->get_record('user_enrolments', ['enrolid' => $this->instanceid], '*', MUST_EXIST);
            $DB->set_field('user_enrolments', 'timeend', $enrolment->timeend - DAYSECS, ['id' => $enrolment->id]);
            $this->assert_error('redirecterrordetected', function () use ($CFG): void {
                global $USER, $PAGE, $OUTPUT;
                require($CFG->dirroot . '/payment/gateway/stripe/process.php');
            });
            $this->assertSame(1, $DB->count_records('payments'));
            $this->assertEquals($enrolment->timeend - DAYSECS, $DB->get_field('user_enrolments', 'timeend', [
                'id' => $enrolment->id,
            ]));
        } finally {
            ApiRequestor::setHttpClient($original);
            $_GET = $get;
            $_POST = $post;
        }
    }

    /**
     * Upgrade protects historical subscriptions without changing their enrolment.
     */
    public function test_upgrade_marks_existing_subscriptions_delivered(): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/payment/gateway/stripe/db/upgrade.php');
        $this->service->save_subscription($this->session);
        \enrol_fee\payment\service_provider::deliver_order('fee', $this->instanceid, 0, $this->userid);
        $before = $DB->get_record('user_enrolments', ['enrolid' => $this->instanceid], '*', MUST_EXIST);
        $this->preventResetByRollback();
        $table = new \xmldb_table('paygw_stripe_subscriptions');
        $field = new \xmldb_field('delivered', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'priceid');
        $dbman = $DB->get_manager();
        $dbman->drop_field($table, $field);
        set_config('version', 2026101900, 'paygw_stripe');
        try {
            $this->assertTrue(xmldb_paygw_stripe_upgrade(2026101900));
            $record = (new subscription_repository())->find_by_subscriptionid('sub_fulfil');
            $this->assertTrue($record->delivered);
            $this->assertFalse($this->fulfil());
            $this->assertSame(0, $DB->count_records('payments'));
            $this->assertEquals($before, $DB->get_record('user_enrolments', ['id' => $before->id], '*', MUST_EXIST));
        } finally {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
    }

    /**
     * Run fulfilment against the fixture purchase.
     *
     * @param string $component Payment component
     * @return bool
     */
    private function fulfil(string $component = 'enrol_fee'): bool {
        return $this->service->fulfil_subscription($this->session->id, $this->userid, $component, 'fee', $this->instanceid);
    }

    /**
     * Assert a Moodle error without hiding unexpected exceptions.
     *
     * @param string $code Expected error
     * @param callable $callback Operation
     */
    private function assert_error(string $code, callable $callback): void {
        try {
            $callback();
            $this->fail('Expected ' . $code);
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }
}
