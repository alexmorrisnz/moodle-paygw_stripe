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

use advanced_testcase;
use paygw_stripe\local\model\subscription as subscription_model;
use ReflectionMethod;
use ReflectionProperty;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Customer;
use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;

global $CFG;
require_once($CFG->dirroot . '/payment/gateway/stripe/.extlib/stripe-php/init.php');

/**
 * Tests for subscription_service.
 */
final class subscription_service_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

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

        $client = new subscription_test_fake_client();
        $productpricingservice = new subscription_test_fake_product_pricing_service();
        $customerservice = new subscription_test_fake_customer_service();
        $webhookservice = new subscription_test_fake_webhook_service();
        $service = new subscription_service($client);
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

        $sessionid = $service->generate_subscription(
            $config,
            new \core_payment\local\entities\payable(20.00, 'USD', 777),
            'Monthly Stripe Product',
            20.00,
            'enrol_fee',
            'fee',
            '84'
        );

        $this->assertSame('cs_test_1', $sessionid);
        $payload = $client->checkout->sessions->createdpayloads[0];
        $this->assertSame('subscription', $payload['mode']);
        $this->assertSame('en', $payload['locale']);
        $this->assertSame('pmc_test_1', $payload['payment_method_configuration']);
        $this->assertArrayHasKey('subscription_data', $payload);
        $this->assertArrayHasKey('trial_end', $payload['subscription_data']);
        $this->assertGreaterThan(time(), $payload['subscription_data']['trial_end']);
        $this->assertSame('cus_test_1', $payload['customer']);
        $this->assertSame('price_test_1', $payload['line_items'][0]['price']->id);
        $this->assertSame([777], $webhookservice->createdfor);
        $this->assertSame([$user->id], $customerservice->createdusers);
        $this->assertNotEmpty($productpricingservice->lastargs);
    }

    /**
     * Tests save_subscription inserts then updates stored subscription record.
     */
    public function test_save_subscription_inserts_and_updates_record(): void {
        global $DB, $USER;

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $client = new subscription_test_fake_client();
        $client->subscriptions->retrieved['sub_test_1'] = (object)['id' => 'sub_test_1', 'status' => 'active'];
        $service = new subscription_service($client);

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

        $client->subscriptions->retrieved['sub_test_1'] = (object)['id' => 'sub_test_1', 'status' => 'past_due'];
        $service->save_subscription($session);

        $updated = $DB->get_record('paygw_stripe_subscriptions', ['subscriptionid' => 'sub_test_1'], '*', MUST_EXIST);
        $this->assertSame('past_due', $updated->status);
    }

    /**
     * Tests get_subscription_status returns the linked subscription status.
     */
    public function test_get_subscription_status_returns_status(): void {
        $client = new subscription_test_fake_client();
        $client->checkout->sessions->retrieved['cs_paid'] = (object)[
            'id' => 'cs_paid',
            'subscription' => 'sub_test_2',
        ];
        $client->subscriptions->retrieved['sub_test_2'] = (object)['id' => 'sub_test_2', 'status' => 'active'];
        $service = new subscription_service($client);

        $this->assertSame('active', $service->get_subscription_status('cs_paid'));
    }

    /**
     * Tests load_portal creates the billing portal session with the expected payload.
     */
    public function test_load_portal_creates_billing_portal_session(): void {
        $client = new subscription_test_fake_client();
        $client->subscriptions->retrieved['sub_test_3'] = (object)[
            'id' => 'sub_test_3',
            'customer' => 'cus_test_3',
        ];
        $client->customers->retrieved['cus_test_3'] = Customer::constructFrom(['id' => 'cus_test_3']);
        $service = new subscription_service($client);

        $subscription = new subscription_model(1, 42, 'sub_test_3', 'cus_test_3', 'active', 'prod_test_a', 'price_test_a');
        $service->load_portal($subscription);

        $payload = $client->billingPortal->sessions->createdpayloads[0];
        $this->assertSame('payment_method_update', $payload['flow_data']['type']);
        $this->assertSame('cus_test_3', $payload['customer']->id);
        $this->assertNotEmpty($client->billingPortal->sessions->createdurls);
    }

    /**
     * Tests cancel_subscription updates stored status without cancelling in Stripe.
     */
    public function test_cancel_subscription_updates_status_without_remote_cancel(): void {
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

        $client = new subscription_test_fake_client();
        $client->subscriptions->retrieved['sub_cancel_1'] = (object)['id' => 'sub_cancel_1', 'status' => 'canceled'];
        $service = new subscription_service($client);

        $moodlesub = new subscription_model(
            (int)$subid,
            (int)$user->id,
            'sub_cancel_1',
            'cus_cancel_1',
            'active',
            'prod_cancel_1',
            'price_cancel_1'
        );
        $service->cancel_subscription($moodlesub, false);

        $updated = $DB->get_record('paygw_stripe_subscriptions', ['id' => $subid], '*', MUST_EXIST);
        $this->assertSame('canceled', $updated->status);
        $this->assertGreaterThan(0, $productid);
    }

    /**
     * Tests get_subscription_table_data returns a row for an active subscription.
     */
    public function test_get_subscription_table_data_returns_row(): void {
        $client = new subscription_test_fake_client();
        $client->products->retrieved['prod_test_1'] = Product::constructFrom(['id' => 'prod_test_1', 'name' => 'Course Access']);
        $price = Price::constructFrom(['id' => 'price_test_1', 'unit_amount' => 1234, 'currency' => 'USD']);
        $price->recurring = (object)['interval' => 'month', 'interval_count' => 1];
        $client->prices->retrieved['price_test_1'] = $price;
        $client->subscriptions->retrieved['sub_test_1'] = (object)[
            'id' => 'sub_test_1',
            'items' => (object)[
                'data' => [
                    (object)['current_period_end' => time() + 3600],
                ],
            ],
        ];
        $service = new subscription_service($client);

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
        $service = new subscription_service(new subscription_test_fake_client());

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
        $service = new subscription_service(new subscription_test_fake_client());
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
        $service = new subscription_service(new subscription_test_fake_client());
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
        $service = new subscription_service(new subscription_test_fake_client());
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

/**
 * Minimal fake Stripe client for unit testing subscription_service without network calls.
 */
final class subscription_test_fake_client extends StripeClient {
    /** @var subscription_test_fake_checkout_sessions_service */
    public $checkout;
    /** @var subscription_test_fake_subscriptions_service */
    public $subscriptions;
    /** @var subscription_test_fake_customers_service */
    public $customers;
    /** @var subscription_test_fake_products_service */
    public $products;
    /** @var subscription_test_fake_prices_service */
    public $prices;
    /** @var subscription_test_fake_billing_portal_service */
    public $billingPortal;

    public function __construct() {
        $this->checkout = (object)['sessions' => new subscription_test_fake_checkout_sessions_service()];
        $this->subscriptions = new subscription_test_fake_subscriptions_service();
        $this->customers = new subscription_test_fake_customers_service();
        $this->products = new subscription_test_fake_products_service();
        $this->prices = new subscription_test_fake_prices_service();
        $this->billingPortal = new subscription_test_fake_billing_portal_service();
    }
}

/**
 * Fake checkout sessions service.
 */
final class subscription_test_fake_checkout_sessions_service {
    /** @var array */
    public $createdpayloads = [];
    /** @var array */
    public $retrieved = [];

    public function create(array $payload): object {
        $this->createdpayloads[] = $payload;
        return (object)['id' => 'cs_test_' . count($this->createdpayloads)];
    }

    public function retrieve(string $sessionid, array $params = []): object {
        return $this->retrieved[$sessionid] ?? (object)[
            'id' => $sessionid,
            'subscription' => 'sub_default',
        ];
    }
}

/**
 * Fake subscription service.
 */
final class subscription_test_fake_subscriptions_service {
    /** @var array */
    public $retrieved = [];
    /** @var array */
    public $canceled = [];

    public function retrieve(string $subscriptionid, array $params = []): object {
        return $this->retrieved[$subscriptionid] ?? (object)['id' => $subscriptionid, 'status' => 'incomplete'];
    }

    public function cancel(string $subscriptionid): object {
        $this->canceled[] = $subscriptionid;
        return (object)['id' => $subscriptionid, 'status' => 'canceled'];
    }
}

/**
 * Fake customers service.
 */
final class subscription_test_fake_customers_service {
    /** @var array */
    public $retrieved = [];

    public function retrieve(string $customerid): Customer {
        return $this->retrieved[$customerid] ?? Customer::constructFrom(['id' => $customerid]);
    }
}

/**
 * Fake products service.
 */
final class subscription_test_fake_products_service {
    /** @var array */
    public $retrieved = [];

    public function retrieve(string $productid): Product {
        return $this->retrieved[$productid] ?? Product::constructFrom(['id' => $productid, 'name' => 'Recovered']);
    }
}

/**
 * Fake prices service.
 */
final class subscription_test_fake_prices_service {
    /** @var array */
    public $retrieved = [];

    public function retrieve(string $priceid): Price {
        return $this->retrieved[$priceid] ?? Price::constructFrom([
            'id' => $priceid,
            'unit_amount' => 0,
            'currency' => 'USD',
            'recurring' => (object)['interval' => 'month', 'interval_count' => 1],
        ]);
    }
}

/**
 * Fake billing portal service.
 */
final class subscription_test_fake_billing_portal_service {
    /** @var subscription_test_fake_billing_portal_sessions_service */
    public $sessions;

    public function __construct() {
        $this->sessions = new subscription_test_fake_billing_portal_sessions_service();
    }
}

/**
 * Fake billing portal sessions service.
 */
final class subscription_test_fake_billing_portal_sessions_service {
    /** @var array */
    public $createdpayloads = [];
    /** @var array */
    public $createdurls = [];

    public function create(array $payload): object {
        $this->createdpayloads[] = $payload;
        $url = 'https://example.test/portal/' . count($this->createdpayloads);
        $this->createdurls[] = $url;
        return (object)['url' => $url];
    }
}

/**
 * Fake product pricing service.
 */
final class subscription_test_fake_product_pricing_service extends product_pricing_service {
    /** @var array */
    public $lastargs = [];

    public function __construct() {
    }

    public function create_product_and_price(
        object $config,
        \core_payment\local\entities\payable $payable,
        string $description,
        float $cost,
        string $component,
        string $paymentarea,
        string $itemid,
        ?array $subscription = null
    ) {
        $this->lastargs = func_get_args();
        $product = Product::constructFrom(['id' => 'prod_test_1', 'name' => $description]);
        $price = Price::constructFrom(['id' => 'price_test_1']);
        return [$product, $price];
    }
}

/**
 * Fake customer service.
 */
final class subscription_test_fake_customer_service extends customer_service {
    /** @var array */
    public $createdusers = [];

    public function __construct() {
    }

    public function get_customer(int $userid): ?Customer {
        return null;
    }

    public function create_customer($user): Customer {
        $this->createdusers[] = $user->id;
        return Customer::constructFrom(['id' => 'cus_test_1']);
    }

    public function update_customer_details(Customer $customer, $user) {
        return Customer::constructFrom(['id' => $customer->id]);
    }
}

/**
 * Fake webhook service.
 */
final class subscription_test_fake_webhook_service extends webhook_service {
    /** @var array */
    public $createdfor = [];

    public function __construct() {
    }

    public function create_webhook(int $paymentaccountid): bool {
        $this->createdfor[] = $paymentaccountid;
        return true;
    }
}

/**
 * Session factory for subscription tests.
 */
final class subscription_test_session_factory {
    public static function subscription_session(
        string $sessionid,
        string $subscriptionid,
        string $customerid,
        string $productid,
        string $priceid
    ): StripeSession {
        $session = StripeSession::constructFrom([
            'id' => $sessionid,
            'mode' => 'subscription',
            'subscription' => $subscriptionid,
        ]);
        $session->customer = (object)['id' => $customerid];
        $session->line_items = new subscription_test_line_items($productid, $priceid);
        return $session;
    }
}

/**
 * Line item collection stub supporting first().
 */
final class subscription_test_line_items {
    /** @var object */
    private $first;

    public function __construct(string $productid, string $priceid) {
        $this->first = (object)[
            'price' => (object)[
                'product' => $productid,
                'id' => $priceid,
            ],
        ];
    }

    public function first(): object {
        return $this->first;
    }
}
