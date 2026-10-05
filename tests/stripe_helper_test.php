<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Tests for stripe helper logic.
 *
 * @package    paygw_stripe
 * @category   test
 * @copyright  2026 Alex Morris
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace paygw_stripe;

use advanced_testcase;
use paygw_stripe\local\service\subscription_service;
use ReflectionProperty;
use Stripe\Event;
use Stripe\StripeClient;

global $CFG;
require_once($CFG->dirroot . '/payment/gateway/stripe/.extlib/stripe-php/init.php');

/**
 * Tests for stripe_helper deterministic behaviour.
 */
final class stripe_helper_test extends advanced_testcase {
    /**
     * Reset config and db state after each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests process_stripe_event rejects malformed and unsupported event types.
     */
    public function test_process_stripe_event_rejects_invalid_payloads(): void {
        $helper = $this->get_helper_without_constructor();
        $this->set_private_property($helper, 'stripe', new stripe_test_fake_client());

        $missingobject = Event::constructFrom([
            'id' => 'evt_missing',
            'type' => 'checkout.session.async_payment_succeeded',
            'data' => [],
        ]);
        $unknown = Event::constructFrom([
            'id' => 'evt_unknown',
            'type' => 'unknown.type',
            'data' => ['object' => ['id' => 'obj_1']],
        ]);

        $this->assertFalse($helper->process_stripe_event($missingobject, []));
        $this->assertFalse($helper->process_stripe_event($unknown, []));
    }

    /**
     * Tests process_stripe_event updates stored subscription status on update events.
     */
    public function test_process_stripe_event_updates_subscription_record(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('paygw_stripe_subscriptions', (object)[
            'userid' => $user->id,
            'subscriptionid' => 'sub_evt_1',
            'customerid' => 'cus_evt_1',
            'status' => 'incomplete',
            'productid' => 'prod_evt_1',
            'priceid' => 'price_evt_1',
        ]);

        $helper = $this->get_helper_without_constructor();
        $stripeclient = new stripe_test_fake_client();
        $stripeclient->subscriptions->retrieved['sub_evt_1'] = (object)['id' => 'sub_evt_1', 'status' => 'active'];
        $this->set_private_property($helper, 'stripe', $stripeclient);
        $this->set_private_property($helper, 'subscriptionservice', new subscription_service($stripeclient));

        $event = Event::constructFrom([
            'id' => 'evt_sub_updated',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_evt_1']],
        ]);

        $this->assertTrue($helper->process_stripe_event($event, []));
        $updated = $DB->get_record('paygw_stripe_subscriptions', ['subscriptionid' => 'sub_evt_1'], '*', MUST_EXIST);
        $this->assertSame('active', $updated->status);
    }

    /**
     * Tests process_stripe_event delegates subscription deletion to the subscription service.
     */
    public function test_process_stripe_event_delegates_subscription_delete(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('paygw_stripe_subscriptions', (object)[
            'userid' => $user->id,
            'subscriptionid' => 'sub_evt_delete',
            'customerid' => 'cus_evt_delete',
            'status' => 'active',
            'productid' => 'prod_evt_delete',
            'priceid' => 'price_evt_delete',
        ]);

        $helper = $this->get_helper_without_constructor();
        $stripeclient = new stripe_test_fake_client();
        $this->set_private_property($helper, 'stripe', $stripeclient);
        $subscriptionservice = new stripe_test_fake_subscription_service($stripeclient);
        $this->set_private_property($helper, 'subscriptionservice', $subscriptionservice);

        $event = Event::constructFrom([
            'id' => 'evt_sub_deleted',
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => ['id' => 'sub_evt_delete']],
        ]);

        $this->assertTrue($helper->process_stripe_event($event, []));
        $this->assertCount(1, $subscriptionservice->cancelcalls);
        $this->assertSame('sub_evt_delete', $subscriptionservice->cancelcalls[0][0]->subscriptionid);
        $this->assertEquals($user->id, $subscriptionservice->cancelcalls[0][0]->userid);
        $this->assertFalse($subscriptionservice->cancelcalls[0][1]);
    }

    /**
     * Builds helper object without running constructor (no Stripe setup needed for these tests).
     *
     * @return stripe_helper
     */
    private function get_helper_without_constructor(): stripe_helper {
        return new stripe_helper('pk_test', 'sk_test');
    }

    /**
     * Sets a private property on stripe_helper instance.
     *
     * @param stripe_helper $helper
     * @param string $name
     * @param mixed $value
     * @return void
     */
    private function set_private_property(stripe_helper $helper, string $name, $value): void {
        $property = new ReflectionProperty(stripe_helper::class, $name);
        $property->setAccessible(true);
        $property->setValue($helper, $value);
    }
}

/**
 * Fake subscription service recording cancellation delegation.
 */
final class stripe_test_fake_subscription_service extends subscription_service {
    /** @var array Recorded cancel_subscription() calls. */
    public $cancelcalls = [];

    /**
     * Record the cancellation instead of calling Stripe.
     *
     * @param \paygw_stripe\local\model\subscription $moodlesub
     * @param bool $cancelstripe
     * @return void
     */
    public function cancel_subscription(\paygw_stripe\local\model\subscription $moodlesub, bool $cancelstripe = true) {
        $this->cancelcalls[] = [$moodlesub, $cancelstripe];
    }
}

/**
 * Minimal fake Stripe client for unit testing stripe_helper without network calls.
 */
final class stripe_test_fake_client extends StripeClient {
    /** @var object */
    public $checkout;
    /** @var stripe_test_fake_subscriptions_service */
    public $subscriptions;

    public function __construct() {
        $this->subscriptions = new stripe_test_fake_subscriptions_service();
        $this->checkout = (object)[
            'sessions' => new stripe_test_fake_checkout_sessions_service(),
        ];
    }
}

/**
 * Fake checkout sessions service.
 */
final class stripe_test_fake_checkout_sessions_service {
    /** @var array */
    public $retrieved = [];

    /**
     * @param string $sessionid
     * @param array $params
     * @return object
     */
    public function retrieve(string $sessionid, array $params = []): object {
        if (isset($this->retrieved[$sessionid])) {
            return $this->retrieved[$sessionid];
        }
        return (object)[
            'id' => $sessionid,
            'mode' => 'payment',
            'payment_status' => 'unpaid',
            'payment_intent' => (object)['status' => 'requires_payment_method'],
            'subscription' => 'sub_default',
        ];
    }
}

/**
 * Fake subscriptions service.
 */
final class stripe_test_fake_subscriptions_service {
    /** @var array */
    public $retrieved = [];

    /**
     * @param string $id
     * @return object
     */
    public function retrieve(string $id): object {
        return $this->retrieved[$id] ?? (object)['id' => $id, 'status' => 'incomplete'];
    }
}
