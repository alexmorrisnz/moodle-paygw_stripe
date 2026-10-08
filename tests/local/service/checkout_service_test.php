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
 * Tests for checkout service logic.
 *
 * @package    paygw_stripe
 * @category   test
 * @copyright  2026 Alex Morris
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace paygw_stripe\local\service;

use advanced_testcase;
use core_payment\local\entities\payable;
use ReflectionProperty;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Customer;
use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;

global $CFG;
require_once($CFG->dirroot . '/payment/gateway/stripe/.extlib/stripe-php/init.php');

/**
 * Tests for checkout_service.
 */
final class checkout_service_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests generating a one-time payment checkout session with Stripe payload values.
     */
    public function test_generate_payment_creates_expected_checkout_payload(): void {
        global $USER;

        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Stripe',
            'lastname' => 'Tester',
            'email' => 'stripe.tester@example.com',
            'username' => 'stripetester',
            'lang' => 'en',
        ]);
        $this->setUser($user);

        $client = new checkout_test_fake_client();
        $productpricingservice = new checkout_test_fake_product_pricing_service();
        $customerservice = new checkout_test_fake_customer_service();
        $webhookservice = new checkout_test_fake_webhook_service();
        $service = new checkout_service($client);
        $this->set_private_property($service, 'productpricingservice', $productpricingservice);
        $this->set_private_property($service, 'customerservice', $customerservice);
        $this->set_private_property($service, 'webhookservice', $webhookservice);

        $config = (object)[
            'enableautomatictax' => 1,
            'defaulttaxbehavior' => 'inclusive',
            'paymentmethodconfiguration' => 'pmc_test_1',
            'collectbillingaddress' => 1,
            'invoicecreation' => 1,
            'allowpromotioncodes' => 1,
        ];

        $sessionid = $service->generate_payment(
            $config,
            new payable(10.50, 'USD', 123),
            'Test Stripe Product',
            10.50,
            'enrol_fee',
            'fee',
            '42'
        );

        $this->assertSame('cs_test_1', $sessionid);
        $this->assertCount(1, $client->checkout->sessions->createdpayloads);

        $payload = $client->checkout->sessions->createdpayloads[0];
        $this->assertSame('payment', $payload['mode']);
        $this->assertSame('en', $payload['locale']);
        $this->assertSame('pmc_test_1', $payload['payment_method_configuration']);
        $this->assertSame('cus_test_1', $payload['customer']);
        $this->assertSame('price_test_1', $payload['line_items'][0]['price']->id);
        $this->assertSame($USER->id, $payload['metadata']['userid']);
        $this->assertSame('enrol_fee', $payload['metadata']['component']);
        $this->assertSame('fee', $payload['metadata']['paymentarea']);
        $this->assertSame('42', $payload['metadata']['itemid']);
        $this->assertSame($payload['metadata'], $payload['payment_intent_data']['metadata']);
        $this->assertSame('required', $payload['billing_address_collection']);
        $this->assertTrue($payload['automatic_tax']['enabled']);
        $this->assertTrue($payload['invoice_creation']['enabled']);
        $this->assertSame('Test Stripe Product', $payload['invoice_creation']['invoice_data']['description']);
        $this->assertTrue($payload['allow_promotion_codes']);
        $this->assertGreaterThan(time(), $payload['expires_at']);
        $this->assertStringContainsString('component=enrol_fee', $payload['success_url']);
        $this->assertStringContainsString('component=enrol_fee', $payload['cancel_url']);

        $this->assertSame([123], $webhookservice->createdfor);
        $this->assertSame([$user->id], $customerservice->createdusers);
        $this->assertNotEmpty($productpricingservice->lastargs);
    }

    /**
     * Tests optional payload flags fall back to disabled values when config is not set.
     */
    public function test_generate_payment_defaults_optional_config_values(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $client = new checkout_test_fake_client();
        $customerservice = new checkout_test_fake_customer_service();
        $customerservice->existingcustomer = Customer::constructFrom(['id' => 'cus_existing']);
        $service = new checkout_service($client);
        $this->set_private_property($service, 'productpricingservice', new checkout_test_fake_product_pricing_service());
        $this->set_private_property($service, 'customerservice', $customerservice);
        $this->set_private_property($service, 'webhookservice', new checkout_test_fake_webhook_service());
        $this->set_private_property($service, 'paymentmethodconfigservice', new checkout_test_fake_payment_method_config_service());

        $config = (object)[
            'enableautomatictax' => 0,
            'defaulttaxbehavior' => 'inclusive',
            'paymentmethodconfiguration' => null,
            'allowpromotioncodes' => 0,
        ];

        $service->generate_payment(
            $config,
            new payable(10.50, 'USD', 123),
            'Test Stripe Product',
            10.50,
            'enrol_fee',
            'fee',
            '42'
        );

        $payload = $client->checkout->sessions->createdpayloads[0];
        $this->assertSame('auto', $payload['billing_address_collection']);
        $this->assertFalse($payload['automatic_tax']['enabled']);
        $this->assertFalse($payload['invoice_creation']['enabled']);
        $this->assertFalse($payload['allow_promotion_codes']);
        $this->assertSame('pmc_default', $payload['payment_method_configuration']);
        $this->assertSame('cus_existing', $payload['customer']);
        $this->assertSame([], $customerservice->createdusers);
        $this->assertSame(['cus_existing'], $customerservice->updatedcustomers);
    }

    /**
     * Tests helpers reading checkout states from Stripe data.
     */
    public function test_session_status_helpers_read_from_stripe_retrieval(): void {
        $client = new checkout_test_fake_client();
        $service = new checkout_service($client);

        $client->checkout->sessions->retrieved['cs_paid'] = (object)[
            'id' => 'cs_paid',
            'mode' => 'payment',
            'payment_status' => 'paid',
            'payment_intent' => (object)['status' => 'succeeded'],
        ];
        $client->checkout->sessions->retrieved['cs_processing'] = (object)[
            'id' => 'cs_processing',
            'mode' => 'subscription',
            'payment_status' => 'unpaid',
            'payment_intent' => (object)['status' => 'processing'],
        ];

        $this->assertSame('payment', $service->get_sessionmode('cs_paid'));
        $this->assertSame('subscription', $service->get_sessionmode('cs_processing'));
        $this->assertTrue($service->is_paid('cs_paid'));
        $this->assertFalse($service->is_paid('cs_processing'));
        $this->assertTrue($service->is_pending('cs_processing'));
        $this->assertFalse($service->is_pending('cs_paid'));
    }

    /**
     * Tests save_checkout_session inserts then updates the stored checkout session record.
     */
    public function test_save_checkout_session_inserts_and_updates_record(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $service = new checkout_service(new checkout_test_fake_client());
        $session = checkout_test_session_factory::payment_session('cs_save_1', 'open', 'unpaid');

        $service->save_checkout_session($session);

        $record = $DB->get_record('paygw_stripe_checkout_sessions', ['checkoutsessionid' => 'cs_save_1'], '*', MUST_EXIST);
        $this->assertEquals($user->id, $record->userid);
        $this->assertSame('pi_save_1', $record->paymentintent);
        $this->assertSame('cus_save_1', $record->customerid);
        $this->assertEquals(1050, $record->amounttotal);
        $this->assertSame('unpaid', $record->paymentstatus);
        $this->assertSame('open', $record->status);
        $this->assertSame('prod_save_1', $record->productid);

        $service->save_checkout_session(checkout_test_session_factory::payment_session('cs_save_1', 'complete', 'paid'));

        $this->assertCount(1, $DB->get_records('paygw_stripe_checkout_sessions', ['checkoutsessionid' => 'cs_save_1']));
        $updated = $DB->get_record('paygw_stripe_checkout_sessions', ['checkoutsessionid' => 'cs_save_1'], '*', MUST_EXIST);
        $this->assertSame('paid', $updated->paymentstatus);
        $this->assertSame('complete', $updated->status);
    }

    /**
     * Tests is_checkout_session_saved reflects stored records.
     */
    public function test_is_checkout_session_saved_reflects_stored_records(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $service = new checkout_service(new checkout_test_fake_client());

        $this->assertFalse($service->is_checkout_session_saved('cs_unknown'));

        $service->save_checkout_session(checkout_test_session_factory::payment_session('cs_known', 'open', 'unpaid'));

        $this->assertTrue($service->is_checkout_session_saved('cs_known'));
    }

    /**
     * Tests save_payment_status stores the checkout session for payment mode sessions.
     */
    public function test_save_payment_status_saves_payment_mode_session(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $client = new checkout_test_fake_client();
        $client->checkout->sessions->retrieved['cs_status_1'] =
            checkout_test_session_factory::payment_session('cs_status_1', 'complete', 'paid');
        $subscriptionservice = new checkout_test_fake_subscription_service();
        $service = new checkout_service($client);
        $this->set_private_property($service, 'subscriptionservice', $subscriptionservice);

        $service->save_payment_status('cs_status_1');

        $record = $DB->get_record('paygw_stripe_checkout_sessions', ['checkoutsessionid' => 'cs_status_1'], '*', MUST_EXIST);
        $this->assertSame('paid', $record->paymentstatus);
        $this->assertSame([], $subscriptionservice->savedsessions);
        $this->assertSame(
            [['cs_status_1', ['expand' => ['line_items', 'customer']]]],
            $client->checkout->sessions->retrievecalls
        );
    }

    /**
     * Tests save_payment_status delegates subscription mode sessions to the subscription service.
     */
    public function test_save_payment_status_delegates_subscription_mode_session(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $client = new checkout_test_fake_client();
        $client->checkout->sessions->retrieved['cs_status_sub'] =
            checkout_test_session_factory::subscription_session('cs_status_sub');
        $subscriptionservice = new checkout_test_fake_subscription_service();
        $service = new checkout_service($client);
        $this->set_private_property($service, 'subscriptionservice', $subscriptionservice);

        $service->save_payment_status('cs_status_sub');

        $this->assertSame(['cs_status_sub'], $subscriptionservice->savedsessions);
        $this->assertFalse($DB->record_exists('paygw_stripe_checkout_sessions', ['checkoutsessionid' => 'cs_status_sub']));
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
 * Fake default payment method configuration lookup.
 */
final class checkout_test_fake_payment_method_config_service extends payment_method_config_service {
    public function __construct() {
    }

    public function get_default_payment_method_config_id(): ?string {
        return 'pmc_default';
    }
}

/**
 * Minimal fake Stripe client for unit testing checkout_service without network calls.
 */
final class checkout_test_fake_client extends StripeClient {
    /** @var object */
    public $checkout;

    public function __construct() {
        $this->checkout = (object)['sessions' => new checkout_test_fake_checkout_sessions_service()];
    }
}

/**
 * Fake checkout sessions service.
 */
final class checkout_test_fake_checkout_sessions_service {
    /** @var array */
    public $createdpayloads = [];
    /** @var array */
    public $retrieved = [];
    /** @var array */
    public $retrievecalls = [];

    /**
     * @param array $payload
     * @return StripeSession
     */
    public function create(array $payload): StripeSession {
        $this->createdpayloads[] = $payload;
        return checkout_test_session_factory::payment_session('cs_test_' . count($this->createdpayloads), 'open', 'unpaid');
    }

    /**
     * @param string $sessionid
     * @param array $params
     * @return object
     */
    public function retrieve(string $sessionid, array $params = []): object {
        $this->retrievecalls[] = [$sessionid, $params];
        return $this->retrieved[$sessionid] ?? (object)[
            'id' => $sessionid,
            'mode' => 'payment',
            'payment_status' => 'unpaid',
            'payment_intent' => (object)['status' => 'requires_payment_method'],
        ];
    }
}

/**
 * Fake product pricing service.
 */
final class checkout_test_fake_product_pricing_service extends product_pricing_service {
    /** @var array */
    public $lastargs = [];

    public function __construct() {
    }

    /**
     * @param object $config
     * @param \core_payment\local\entities\payable $payable
     * @param string $description
     * @param float $cost
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @param array|null $subscription
     * @return array
     */
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
final class checkout_test_fake_customer_service extends customer_service {
    /** @var array */
    public $createdusers = [];
    /** @var array */
    public $updatedcustomers = [];
    /** @var Customer|null */
    public $existingcustomer = null;

    public function __construct() {
    }

    /**
     * @param int $userid
     * @return Customer|null
     */
    public function get_customer(int $userid): ?Customer {
        return $this->existingcustomer;
    }

    /**
     * @param object $user
     * @return Customer
     */
    public function create_customer($user): Customer {
        $this->createdusers[] = $user->id;
        return Customer::constructFrom(['id' => 'cus_test_1']);
    }

    /**
     * @param Customer $customer
     * @param object $user
     * @return Customer
     */
    public function update_customer_details(Customer $customer, $user) {
        $this->updatedcustomers[] = $customer->id;
        return Customer::constructFrom(['id' => $customer->id]);
    }
}

/**
 * Fake webhook service.
 */
final class checkout_test_fake_webhook_service extends webhook_service {
    /** @var array */
    public $createdfor = [];

    public function __construct() {
    }

    /**
     * @param int $paymentaccountid
     * @return bool
     */
    public function create_webhook(int $paymentaccountid): bool {
        $this->createdfor[] = $paymentaccountid;
        return true;
    }
}

/**
 * Fake subscription service recording delegated sessions.
 */
final class checkout_test_fake_subscription_service extends subscription_service {
    /** @var array */
    public $savedsessions = [];

    public function __construct() {
    }

    /**
     * @param StripeSession $session
     * @return void
     */
    public function save_subscription(StripeSession $session) {
        $this->savedsessions[] = $session->id;
    }
}

/**
 * Session factory for checkout tests.
 */
final class checkout_test_session_factory {
    /**
     * Builds a payment mode session with line items and customer expanded.
     *
     * @param string $sessionid
     * @param string $status
     * @param string $paymentstatus
     * @return StripeSession
     */
    public static function payment_session(string $sessionid, string $status, string $paymentstatus): StripeSession {
        $session = StripeSession::constructFrom([
            'id' => $sessionid,
            'mode' => 'payment',
            'payment_intent' => 'pi_save_1',
            'amount_total' => 1050,
            'payment_status' => $paymentstatus,
            'status' => $status,
        ]);
        $session->customer = (object)['id' => 'cus_save_1'];
        $session->line_items = new checkout_test_line_items('prod_save_1');
        return $session;
    }

    /**
     * Builds a subscription mode session.
     *
     * @param string $sessionid
     * @return StripeSession
     */
    public static function subscription_session(string $sessionid): StripeSession {
        return StripeSession::constructFrom([
            'id' => $sessionid,
            'mode' => 'subscription',
            'subscription' => 'sub_test_1',
        ]);
    }
}

/**
 * Line item collection stub supporting first().
 */
final class checkout_test_line_items {
    /** @var object */
    private $first;

    public function __construct(string $productid) {
        $this->first = (object)['price' => (object)['product' => $productid]];
    }

    /**
     * @return object
     */
    public function first(): object {
        return $this->first;
    }
}
