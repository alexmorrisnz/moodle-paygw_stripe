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

namespace paygw_stripe;

defined('MOODLE_INTERNAL') || die();

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

global $CFG;
require_once($CFG->dirroot . '/payment/gateway/stripe/.extlib/stripe-php/init.php');

/**
 * Regression tests for subscription cancellation requests.
 *
 * @package paygw_stripe
 * @category test
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class subscriptions_test extends \advanced_testcase {
    /** @var int Subscription owner's user ID. */
    private int $userid;
    /** @var int Local subscription ID. */
    private int $subscriptionid;
    /** @var int Fee enrolment instance ID. */
    private int $instanceid;
    /** @var ClientInterface|null Original SDK HTTP client. */
    private ?ClientInterface $originalhttpclient;
    /** @var array Original request globals. */
    private array $request;

    /**
     * Create an active subscription with a fee enrolment.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->request = [$_GET, $_POST, $_SERVER];
        $property = new \ReflectionProperty(ApiRequestor::class, '_httpClient');
        $this->originalhttpclient = $property->getValue();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->userid = (int)$user->id;
        $account = $this->getDataGenerator()->get_plugin_generator('core_payment')
            ->create_payment_account(['gateways' => 'stripe']);
        $DB->set_field('payment_gateways', 'config', json_encode([
            'apikey' => 'pk_test_fake',
            'secretkey' => 'sk_test_fake',
        ]), ['accountid' => $account->get('id'), 'gateway' => 'stripe']);
        $course = $this->getDataGenerator()->create_course();
        $plugin = enrol_get_plugin('fee');
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $this->instanceid = (int)$plugin->add_instance($course, [
            'customint1' => $account->get('id'),
            'cost' => 10,
            'currency' => 'USD',
            'roleid' => $roleid,
        ]);
        $instance = $DB->get_record('enrol', ['id' => $this->instanceid], '*', MUST_EXIST);
        $plugin->enrol_user($instance, $this->userid, $roleid);
        $DB->insert_record('paygw_stripe_products', (object)[
            'component' => 'enrol_fee',
            'paymentarea' => 'fee',
            'itemid' => $this->instanceid,
            'productid' => 'prod_cancel',
        ]);
        $this->subscriptionid = (int)$DB->insert_record('paygw_stripe_subscriptions', (object)[
            'userid' => $this->userid,
            'subscriptionid' => 'sub_cancel',
            'customerid' => 'cus_cancel',
            'status' => 'active',
            'productid' => 'prod_cancel',
            'priceid' => 'price_cancel',
        ]);
    }

    /**
     * Restore request globals and the SDK HTTP client.
     */
    protected function tearDown(): void {
        [$_GET, $_POST, $_SERVER] = $this->request;
        ApiRequestor::setHttpClient($this->originalhttpclient);
        parent::tearDown();
    }

    /**
     * Requests without a valid session key.
     *
     * @return array
     */
    public static function rejected_requests_provider(): array {
        return [
            'GET without sesskey' => ['GET', null, 'missingparam'],
            'GET with invalid sesskey' => ['GET', 'invalid', 'invalidsesskey'],
            'POST without sesskey' => ['POST', null, 'missingparam'],
            'POST with invalid sesskey' => ['POST', 'invalid', 'invalidsesskey'],
        ];
    }

    /**
     * Rejected cancellation requests must not contact Stripe or remove access.
     *
     * @param string $method Request method
     * @param string|null $token Token validity, or null to omit it
     * @param string $error Expected error code
     */
    #[DataProvider('rejected_requests_provider')]
    public function test_rejects_unsafe_cancellation(string $method, ?string $token, string $error): void {
        $http = $this->createMock(ClientInterface::class);
        $http->expects($this->never())->method('request');
        ApiRequestor::setHttpClient($http);

        $this->assertSame($error, $this->cancel($method, $token));
        $this->assert_subscription_state('active', true);
    }

    /**
     * A valid token does not allow cancellation of someone else's subscription.
     */
    public function test_rejects_another_users_subscription(): void {
        $http = $this->createMock(ClientInterface::class);
        $http->expects($this->never())->method('request');
        ApiRequestor::setHttpClient($http);
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertSame('subscriptioninvalid', $this->cancel('POST', 'valid'));
        $this->assert_subscription_state('active', true);
    }

    /**
     * Request methods supported with a valid session key.
     *
     * @return array
     */
    public static function valid_requests_provider(): array {
        return [
            'POST with valid sesskey' => ['POST'],
            'GET with valid sesskey' => ['GET'],
        ];
    }

    /**
     * A valid session key permits cancellation and removal of the fee enrolment.
     *
     * @param string $method Request method
     */
    #[DataProvider('valid_requests_provider')]
    public function test_valid_sesskey_cancels_subscription(string $method): void {
        $http = $this->createMock(ClientInterface::class);
        $http->expects($this->once())->method('request')
            ->with('delete', 'https://api.stripe.com/v1/subscriptions/sub_cancel')
            ->willReturn([json_encode([
                'id' => 'sub_cancel',
                'object' => 'subscription',
                'status' => 'canceled',
            ]), 200, []]);
        ApiRequestor::setHttpClient($http);

        // CLI execution raises this exception instead of issuing the final redirect.
        $this->assertSame('redirecterrordetected', $this->cancel($method, 'valid'));
        $this->assert_subscription_state('canceled', false);
    }

    /**
     * Run the actual cancellation endpoint with a simulated browser request.
     *
     * @param string $method Request method
     * @param string|null $token Token validity, or null to omit it
     * @return string Endpoint exception code
     */
    private function cancel(string $method, ?string $token): string {
        global $CFG, $USER, $PAGE, $OUTPUT;

        $params = ['action' => 'cancel', 'subscriptionid' => $this->subscriptionid];
        if ($token !== null) {
            $params['sesskey'] = $token === 'valid' ? sesskey() : 'invalid';
        }
        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET = $method === 'GET' ? $params : [];
        $_POST = $method === 'POST' ? $params : [];
        $cwd = getcwd();
        chdir($CFG->dirroot . '/payment/gateway/stripe');
        try {
            require($CFG->dirroot . '/payment/gateway/stripe/subscriptions.php');
        } catch (\moodle_exception $e) {
            return $e->errorcode;
        } finally {
            chdir($cwd);
        }
        $this->fail('Expected cancellation endpoint to reject or redirect.');
    }

    /**
     * Check both persisted subscription status and course access.
     *
     * @param string $status Expected subscription status
     * @param bool $enrolled Whether the fee enrolment should remain
     */
    private function assert_subscription_state(string $status, bool $enrolled): void {
        global $DB;

        $this->assertSame($status, $DB->get_field('paygw_stripe_subscriptions', 'status', ['id' => $this->subscriptionid]));
        $this->assertSame($enrolled, $DB->record_exists('user_enrolments', [
            'enrolid' => $this->instanceid,
            'userid' => $this->userid,
        ]));
    }
}
