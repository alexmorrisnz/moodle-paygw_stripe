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

/**
 * Tests for subscription service logic.
 *
 * @package    paygw_stripe
 * @category   test
 * @copyright  2026 Alex Morris
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace paygw_stripe\local\service;

defined('MOODLE_INTERNAL') || die();

use paygw_stripe\local\model\subscription as subscription_model;
use paygw_stripe\tests\fixtures\stripe_testcase;
use ReflectionMethod;
use ReflectionProperty;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Product;
use Stripe\Price;
use Stripe\Subscription;
use Stripe\Service\SubscriptionService;
use Stripe\Service\CustomerService;
use Stripe\Service\ProductService;
use Stripe\Service\PriceService;
use Stripe\Service\Checkout\CheckoutServiceFactory;
use Stripe\Service\Checkout\SessionService;
use Stripe\Service\BillingPortal\BillingPortalServiceFactory;
use Stripe\Service\BillingPortal\SessionService as PortalSessionService;

require_once(__DIR__ . '/../../fixtures/stripe_testcase.php');
require_once(__DIR__ . '/../../fixtures/subscription_test_session_factory.php');

/**
 * Tests for subscription_service.
 *
 * @covers \paygw_stripe\local\service\subscription_service
 */
final class subscription_service_test extends stripe_testcase {
    /**
     * Tests subscription checkout payload includes trial configuration details.
     */
    public function test_generate_subscription_with_trial_sets_trial_end(): void {
        global $USER;

        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Stripe',
            'lastname' => 'Tester',
            'email' => 'stripe.tester@example.com',
            'username' => 'stripetester',
            'lang' => 'en',
        ]);
        $this->setUser($user);

        $productpricingservice = $this->createMock(product_pricing_service::class);
        $customerservice = $this->createMock(customer_service::class);
        $customerservice->expects($this->once())->method('get_customer')->with($user->id)->willReturn(null);
        $customerservice->expects($this->once())->method('create_customer')
            ->with($this->callback(static fn(object $actual): bool => $actual->id === $user->id))
            ->willReturn(Customer::constructFrom(['id' => 'cus_test_1']));
        $customerservice->expects($this->never())->method('update_customer_details');
        $webhookservice = $this->createMock(webhook_service::class);
        $webhookservice->expects($this->once())->method('create_webhook')->with(777)->willReturn(true);
        $checkout = $this->mock_stripe_service('checkout', CheckoutServiceFactory::class);
        $sessions = $this->createMock(SessionService::class);
        $checkout->method('__get')->with('sessions')->willReturn($sessions);
        $payload = [];
        $sessions->expects($this->once())->method('create')
            ->willReturnCallback(function (array $params) use (&$payload): Session {
                $payload = $params;
                return Session::constructFrom(['id' => 'cs_test_1']);
            });
        $service = new subscription_service($this->client);
        $this->set_private_property($service, 'productpricingservice', $productpricingservice);
        $this->set_private_property($service, 'customerservice', $customerservice);
        $this->set_private_property($service, 'webhookservice', $webhookservice);
        $this->set_private_property($service, 'localeservice', new locale_service());

        $config = (object)[
            'enableautomatictax' => 0,
            'defaulttaxbehavior' => 'inclusive',
            'paymentmethodconfiguration' => 'pmc_test_1',
            'collectbillingaddress' => 0,
            'allowpromotioncodes' => 0,
            'subscriptioninterval' => 'monthly',
            'customsubscriptioninterval' => 'month',
            'customsubscriptionintervalcount' => 1,
            'anchorbilling' => 0,
            'firstintervalfree' => 1,
        ];
        $payable = new \core_payment\local\entities\payable(20.00, 'USD', 777);
        $productpricingservice->expects($this->once())->method('create_product_and_price')
            ->with(
                $config,
                $payable,
                'Monthly Stripe Product',
                20.00,
                'enrol_fee',
                'fee',
                '84',
                ['interval' => 'month', 'interval_count' => 1]
            )
            ->willReturn([Product::constructFrom(['id' => 'prod_test_1']), Price::constructFrom(['id' => 'price_test_1'])]);

        $sessionid = $service->generate_subscription(
            $config,
            $payable,
            'Monthly Stripe Product',
            20.00,
            'enrol_fee',
            'fee',
            '84'
        );

        $this->assertSame('cs_test_1', $sessionid);
        $this->assertSame('subscription', $payload['mode']);
        $this->assertSame('en', $payload['locale']);
        $this->assertSame('pmc_test_1', $payload['payment_method_configuration']);
        $this->assertArrayHasKey('subscription_data', $payload);
        $this->assertArrayHasKey('trial_end', $payload['subscription_data']);
        $this->assertGreaterThan(time(), $payload['subscription_data']['trial_end']);
        $this->assertSame('cus_test_1', $payload['customer']);
        $this->assertSame('price_test_1', $payload['line_items'][0]['price']->id);
        $this->assertSame($USER->id, $payload['metadata']['userid']);
        $this->assertSame($payload['metadata'], $payload['subscription_data']['metadata']);
    }

    /**
     * Tests save_subscription inserts then updates stored subscription record.
     */
    public function test_save_subscription_inserts_and_updates_record(): void {
        global $DB, $USER;

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $subscriptions = $this->mock_stripe_service('subscriptions', SubscriptionService::class);
        $subscriptions->expects($this->exactly(2))->method('retrieve')->with('sub_test_1')->willReturn(
            Subscription::constructFrom(['id' => 'sub_test_1', 'status' => 'active']),
            Subscription::constructFrom(['id' => 'sub_test_1', 'status' => 'past_due'])
        );
        $service = new subscription_service($this->client);

        $session = subscription_test_session_factory::subscription_session(
            'cs_sub_1',
            'sub_test_1',
            'cus_test_1',
            'prod_test_a',
            'price_test_a'
        );

        $service->save_subscription($session);
        $record = $DB->get_record('paygw_stripe_subscriptions', ['subscriptionid' => 'sub_test_1'], '*', MUST_EXIST);
        $this->assertSame((int)$USER->id, (int)$record->userid);
        $this->assertSame('active', $record->status);

        $service->save_subscription($session);

        $updated = $DB->get_record('paygw_stripe_subscriptions', ['subscriptionid' => 'sub_test_1'], '*', MUST_EXIST);
        $this->assertSame('past_due', $updated->status);
    }

    /**
     * Tests get_subscription_status returns the linked subscription status.
     */
    public function test_get_subscription_status_returns_status(): void {
        $checkout = $this->mock_stripe_service('checkout', CheckoutServiceFactory::class);
        $sessions = $this->createMock(SessionService::class);
        $checkout->method('__get')->with('sessions')->willReturn($sessions);
        $sessions->expects($this->once())->method('retrieve')->with('cs_paid')
            ->willReturn(Session::constructFrom(['id' => 'cs_paid', 'subscription' => 'sub_test_2']));
        $subscriptions = $this->mock_stripe_service('subscriptions', SubscriptionService::class);
        $subscriptions->expects($this->once())->method('retrieve')->with('sub_test_2')
            ->willReturn(Subscription::constructFrom(['id' => 'sub_test_2', 'status' => 'active']));
        $service = new subscription_service($this->client);

        $this->assertSame('active', $service->get_subscription_status('cs_paid'));
    }

    /**
     * Tests load_portal creates the billing portal session with the expected payload.
     */
    public function test_load_portal_creates_billing_portal_session(): void {
        $subscriptions = $this->mock_stripe_service('subscriptions', SubscriptionService::class);
        $subscriptions->expects($this->once())->method('retrieve')->with('sub_test_3')
            ->willReturn(Subscription::constructFrom(['id' => 'sub_test_3', 'customer' => 'cus_test_3']));
        $customer = Customer::constructFrom(['id' => 'cus_test_3']);
        $customers = $this->mock_stripe_service('customers', CustomerService::class);
        $customers->expects($this->once())->method('retrieve')->with('cus_test_3')->willReturn($customer);
        $portal = $this->mock_stripe_service('billingPortal', BillingPortalServiceFactory::class);
        $sessions = $this->createMock(PortalSessionService::class);
        $portal->method('__get')->with('sessions')->willReturn($sessions);
        $payload = [];
        $sessions->expects($this->once())->method('create')
            ->willReturnCallback(function (array $params) use (&$payload): \Stripe\BillingPortal\Session {
                $payload = $params;
                return \Stripe\BillingPortal\Session::constructFrom(['id' => 'bps_1', 'url' => 'https://example.test/portal']);
            });
        $service = new subscription_service($this->client);

        $subscription = new subscription_model(1, 42, 'sub_test_3', 'cus_test_3', 'active', 'prod_test_a', 'price_test_a');
        $service->load_portal($subscription);

        $this->assertSame('payment_method_update', $payload['flow_data']['type']);
        $this->assertSame($customer, $payload['customer']);
        $this->assertStringContainsString('/payment/gateway/stripe/subscriptions.php', $payload['return_url']);
        $this->assertSame($payload['return_url'], $payload['flow_data']['after_completion']['redirect']['return_url']);
    }

    /**
     * Provide remote cancellation and webhook-driven status synchronization.
     *
     * @return array Cancellation modes
     */
    public static function cancellation_provider(): array {
        return ['remote cancellation' => [true], 'webhook synchronization' => [false]];
    }

    /**
     * Tests cancellation updates stored status using the appropriate SDK method.
     *
     * @dataProvider cancellation_provider
     * @param bool $cancelstripe Whether to cancel remotely
     */
    public function test_cancel_subscription_updates_status(bool $cancelstripe): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $productid = $DB->insert_record('paygw_stripe_products', (object)[
            'component' => 'mod_forum',
            'paymentarea' => 'post',
            'itemid' => 999,
            'productid' => 'prod_cancel_1',
        ]);
        $subid = $DB->insert_record('paygw_stripe_subscriptions', (object)[
            'userid' => $user->id,
            'subscriptionid' => 'sub_cancel_1',
            'customerid' => 'cus_cancel_1',
            'status' => 'active',
            'productid' => 'prod_cancel_1',
            'priceid' => 'price_cancel_1',
        ]);

        $subscriptions = $this->mock_stripe_service('subscriptions', SubscriptionService::class);
        $subscriptions->expects($this->once())->method($cancelstripe ? 'cancel' : 'retrieve')->with('sub_cancel_1')
            ->willReturn(Subscription::constructFrom(['id' => 'sub_cancel_1', 'status' => 'canceled']));
        $subscriptions->expects($this->never())->method($cancelstripe ? 'retrieve' : 'cancel');
        $service = new subscription_service($this->client);

        $moodlesub = new subscription_model(
            (int)$subid,
            (int)$user->id,
            'sub_cancel_1',
            'cus_cancel_1',
            'active',
            'prod_cancel_1',
            'price_cancel_1'
        );
        $service->cancel_subscription($moodlesub, $cancelstripe);

        $updated = $DB->get_record('paygw_stripe_subscriptions', ['id' => $subid], '*', MUST_EXIST);
        $this->assertSame('canceled', $updated->status);
        $this->assertGreaterThan(0, $productid);
    }

    /**
     * Tests get_subscription_table_data returns a row for an active subscription.
     */
    public function test_get_subscription_table_data_returns_row(): void {
        $products = $this->mock_stripe_service('products', ProductService::class);
        $products->expects($this->once())->method('retrieve')->with('prod_test_1')->willReturn(Product::constructFrom([
            'id' => 'prod_test_1',
            'object' => 'product',
            'name' => 'Course Access',
        ]));
        $prices = $this->mock_stripe_service('prices', PriceService::class);
        $prices->expects($this->once())->method('retrieve')->with('price_test_1')->willReturn(Price::constructFrom([
            'id' => 'price_test_1',
            'object' => 'price',
            'unit_amount' => 1234,
            'currency' => 'USD',
            'recurring' => ['interval' => 'month', 'interval_count' => 1],
        ]));
        $subscriptions = $this->mock_stripe_service('subscriptions', SubscriptionService::class);
        $subscriptions->expects($this->once())->method('retrieve')
            ->with('sub_test_1', ['expand' => ['schedule']])->willReturn(Subscription::constructFrom([
            'id' => 'sub_test_1',
            'object' => 'subscription',
            'items' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'si_test_1',
                    'object' => 'subscription_item',
                    'current_period_end' => time() + 3600,
                ]],
                'has_more' => false,
                'url' => '/v1/subscription_items?subscription=sub_test_1',
            ],
        ]));
        $service = new subscription_service($this->client);

        $moodlesub = new subscription_model(1, 42, 'sub_test_1', 'cus_test_1', 'active', 'prod_test_1', 'price_test_1');

        $row = $service->get_subscription_table_data($moodlesub);
        $this->assertSame('Course Access', $row[0]);
        $this->assertStringContainsString(' / ', $row[1]);
        $this->assertStringContainsString('action=portal', $row[4]);
        $this->assertStringContainsString('cancel.php', $row[5]);
    }

    /**
     * Tests localised cost keeps currency amount semantics for decimal and zero-decimal currencies.
     */
    public function test_get_localised_cost_preserves_amount_semantics(): void {
        $service = new subscription_service($this->client);

        $usd = $service->get_localised_cost(1234.0, 'USD');
        $jpy = $service->get_localised_cost(123.0, 'JPY');
        $locale = get_string('localecldr', 'langconfig');
        $formatter = \NumberFormatter::create($locale, \NumberFormatter::CURRENCY);
        $usdcurrency = '';
        $jpycurrency = '';
        $usdamount = numfmt_parse_currency($formatter, $usd, $usdcurrency);
        $jpyamount = numfmt_parse_currency($formatter, $jpy, $jpycurrency);

        $this->assertSame('USD', $usdcurrency);
        $this->assertSame('JPY', $jpycurrency);
        $this->assertEqualsWithDelta(12.34, $usdamount, 0.01);
        $this->assertEqualsWithDelta(123.0, $jpyamount, 0.01);
    }

    /**
     * Data provider for subscription interval price details.
     *
     * @return array
     */
    public static function subscription_interval_provider(): array {
        return [
            'daily' => ['daily', null, null, ['interval' => 'day', 'interval_count' => 1]],
            'weekly' => ['weekly', null, null, ['interval' => 'week', 'interval_count' => 1]],
            'monthly' => ['monthly', null, null, ['interval' => 'month', 'interval_count' => 1]],
            '3-monthly' => ['every3months', null, null, ['interval' => 'month', 'interval_count' => 3]],
            '6-monthly' => ['every6months', null, null, ['interval' => 'month', 'interval_count' => 6]],
            'yearly' => ['yearly', null, null, ['interval' => 'year', 'interval_count' => 1]],
            'custom' => ['custom', 'week', 2, ['interval' => 'week', 'interval_count' => 2]],
            'default' => ['invalid', null, null, ['interval' => 'month', 'interval_count' => 1]],
        ];
    }

    /**
     * Tests conversion of config subscription interval into Stripe price details.
     *
     * @dataProvider subscription_interval_provider
     * @param string $interval
     * @param string|null $custominterval
     * @param int|null $customcount
     * @param array $expected
     */
    public function test_get_subscription_config_price_details(
        string $interval,
        ?string $custominterval,
        ?int $customcount,
        array $expected
    ): void {
        $service = new subscription_service($this->client);
        $config = (object)[
            'subscriptioninterval' => $interval,
            'customsubscriptioninterval' => $custominterval,
            'customsubscriptionintervalcount' => $customcount,
        ];

        $actual = $this->invoke_private_method($service, 'get_subscription_config_price_details', [$config]);

        $this->assertSame($expected, $actual);
    }

    /**
     * Data provider for anchored billing date configs.
     *
     * @return array
     */
    public static function anchor_billing_provider(): array {
        return [
            'daily' => ['daily', null, null],
            'monthly' => ['monthly', null, null],
            'yearly' => ['yearly', null, null],
            'custom days' => ['custom', 'day', 2],
            'custom weeks' => ['custom', 'week', 2],
        ];
    }

    /**
     * Tests anchored billing date calculation returns UTC midnight dates.
     *
     * @dataProvider anchor_billing_provider
     * @param string $interval
     * @param string|null $custominterval
     * @param int|null $customcount
     */
    public function test_get_anchor_billing_dates_return_utc_midnight(
        string $interval,
        ?string $custominterval,
        ?int $customcount
    ): void {
        $service = new subscription_service($this->client);
        $config = (object)[
            'subscriptioninterval' => $interval,
            'customsubscriptioninterval' => $custominterval,
            'customsubscriptionintervalcount' => $customcount,
        ];

        $anchor = $this->invoke_private_method($service, 'get_anchor_billing_dates', [$config]);

        $this->assertSame('UTC', $anchor->getTimezone()->getName());
        $this->assertSame('00:00:00', $anchor->format('H:i:s'));
    }

    /**
     * Data provider for trial end date configs.
     *
     * @return array
     */
    public static function trial_end_provider(): array {
        return [
            'daily' => ['daily', null, null],
            'weekly' => ['weekly', null, null],
            'monthly' => ['monthly', null, null],
            'yearly' => ['yearly', null, null],
            'custom months' => ['custom', 'month', 2],
        ];
    }

    /**
     * Tests trial end date calculation returns UTC midnight dates.
     *
     * @dataProvider trial_end_provider
     * @param string $interval
     * @param string|null $custominterval
     * @param int|null $customcount
     */
    public function test_get_trial_end_date_return_utc_midnight(
        string $interval,
        ?string $custominterval,
        ?int $customcount
    ): void {
        $service = new subscription_service($this->client);
        $config = (object)[
            'subscriptioninterval' => $interval,
            'customsubscriptioninterval' => $custominterval,
            'customsubscriptionintervalcount' => $customcount,
        ];

        $trialend = $this->invoke_private_method($service, 'get_trial_end_date', [$config]);

        $this->assertSame('UTC', $trialend->getTimezone()->getName());
        $this->assertSame('00:00:00', $trialend->format('H:i:s'));
    }

    /**
     * Invokes a private instance method on subscription_service.
     *
     * @param object $object
     * @param string $methodname
     * @param array $args
     * @return mixed
     */
    private function invoke_private_method(object $object, string $methodname, array $args = []) {
        $method = new ReflectionMethod(subscription_service::class, $methodname);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $args);
    }

    /**
     * Sets a private property on an object.
     *
     * @param object $object
     * @param string $name
     * @param mixed $value
     * @return void
     */
    private function set_private_property(object $object, string $name, $value): void {
        $property = new ReflectionProperty($object, $name);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
