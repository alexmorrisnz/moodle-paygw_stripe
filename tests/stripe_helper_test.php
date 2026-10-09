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

defined('MOODLE_INTERNAL') || die();

use paygw_stripe\local\service\subscription_service;
use paygw_stripe\tests\fixtures\stripe_testcase;
use ReflectionProperty;
use Stripe\Event;
use Stripe\Service\SubscriptionService;
use Stripe\Subscription;

require_once(__DIR__ . '/fixtures/stripe_testcase.php');

/**
 * Tests for stripe_helper deterministic behaviour.
 *
 * @covers \paygw_stripe\stripe_helper
 */
final class stripe_helper_test extends stripe_testcase {
    /**
     * Tests process_stripe_event rejects malformed and unsupported event types.
     */
    public function test_process_stripe_event_rejects_invalid_payloads(): void {
        $helper = $this->get_helper_without_constructor();
        $this->set_private_property($helper, 'stripe', $this->client);

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
        $stripeclient = $this->client;
        $subscriptions = $this->mock_stripe_service('subscriptions', SubscriptionService::class);
        $subscriptions->expects($this->once())->method('retrieve')->with('sub_evt_1')
            ->willReturn(Subscription::constructFrom(['id' => 'sub_evt_1', 'status' => 'active']));
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
        $stripeclient = $this->client;
        $this->set_private_property($helper, 'stripe', $stripeclient);
        $subscriptionservice = $this->getMockBuilder(subscription_service::class)
            ->setConstructorArgs([$stripeclient])
            ->onlyMethods(['cancel_subscription'])
            ->getMock();
        $subscriptionservice->expects($this->once())->method('cancel_subscription')->with(
            $this->callback(function ($subscription) use ($user): bool {
                $this->assertSame('sub_evt_delete', $subscription->subscriptionid);
                $this->assertEquals($user->id, $subscription->userid);
                return true;
            }),
            false
        );
        $this->set_private_property($helper, 'subscriptionservice', $subscriptionservice);

        $event = Event::constructFrom([
            'id' => 'evt_sub_deleted',
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => ['id' => 'sub_evt_delete']],
        ]);

        $this->assertTrue($helper->process_stripe_event($event, []));
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
