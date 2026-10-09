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

defined('MOODLE_INTERNAL') || die();

use core_payment\local\entities\payable;
use paygw_stripe\tests\fixtures\stripe_testcase;
use ReflectionProperty;
use Stripe\Customer;
use Stripe\Product;
use Stripe\Price;
use Stripe\Checkout\Session;
use Stripe\Service\Checkout\CheckoutServiceFactory;
use Stripe\Service\Checkout\SessionService;
use PHPUnit\Framework\MockObject\MockObject;

require_once(__DIR__ . '/../../fixtures/stripe_testcase.php');
require_once(__DIR__ . '/../../fixtures/checkout_test_session_factory.php');

/**
 * Tests for checkout_service.
 *
 * @covers \paygw_stripe\local\service\checkout_service
 */
final class checkout_service_test extends stripe_testcase {
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

        $productpricingservice = $this->createMock(product_pricing_service::class);
        $customerservice = $this->createMock(customer_service::class);
        $customerservice->expects($this->once())->method('get_customer')->with($user->id)->willReturn(null);
        $customerservice->expects($this->once())->method('create_customer')
            ->with($this->callback(static fn(object $actual): bool => $actual->id === $user->id))
            ->willReturn(Customer::constructFrom(['id' => 'cus_test_1']));
        $customerservice->expects($this->never())->method('update_customer_details');
        $webhookservice = $this->createMock(webhook_service::class);
        $webhookservice->expects($this->once())->method('create_webhook')->with(123)->willReturn(true);
        $payload = [];
        $this->mock_sessions()->expects($this->once())->method('create')
            ->willReturnCallback(function (array $params) use (&$payload): Session {
                $payload = $params;
                return checkout_test_session_factory::payment_session('cs_test_1', 'open', 'unpaid');
            });
        $service = new checkout_service($this->client);
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
        $payable = new payable(10.50, 'USD', 123);
        $productpricingservice->expects($this->once())->method('create_product_and_price')
            ->with($config, $payable, 'Test Stripe Product', 10.50, 'enrol_fee', 'fee', '42')
            ->willReturn([Product::constructFrom(['id' => 'prod_test_1']), Price::constructFrom(['id' => 'price_test_1'])]);

        $sessionid = $service->generate_payment(
            $config,
            $payable,
            'Test Stripe Product',
            10.50,
            'enrol_fee',
            'fee',
            '42'
        );

        $this->assertSame('cs_test_1', $sessionid);
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
    }

    /**
     * Tests optional payload flags fall back to disabled values when config is not set.
     */
    public function test_generate_payment_defaults_optional_config_values(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $customer = Customer::constructFrom(['id' => 'cus_existing']);
        $customerservice = $this->createMock(customer_service::class);
        $customerservice->expects($this->once())->method('get_customer')->with($user->id)->willReturn($customer);
        $customerservice->expects($this->never())->method('create_customer');
        $customerservice->expects($this->once())->method('update_customer_details')
            ->with($customer, $this->callback(static fn(object $actual): bool => $actual->id === $user->id))
            ->willReturn($customer);
        $productpricingservice = $this->createMock(product_pricing_service::class);
        $webhookservice = $this->createMock(webhook_service::class);
        $webhookservice->expects($this->once())->method('create_webhook')->with(123)->willReturn(true);
        $methodconfig = $this->createMock(payment_method_config_service::class);
        $methodconfig->expects($this->once())->method('get_default_payment_method_config_id')->willReturn('pmc_default');
        $payload = [];
        $this->mock_sessions()->expects($this->once())->method('create')
            ->willReturnCallback(function (array $params) use (&$payload): Session {
                $payload = $params;
                return checkout_test_session_factory::payment_session('cs_test_1', 'open', 'unpaid');
            });
        $service = new checkout_service($this->client);
        $this->set_private_property($service, 'productpricingservice', $productpricingservice);
        $this->set_private_property($service, 'customerservice', $customerservice);
        $this->set_private_property($service, 'webhookservice', $webhookservice);
        $this->set_private_property($service, 'paymentmethodconfigservice', $methodconfig);

        $config = (object)[
            'enableautomatictax' => 0,
            'defaulttaxbehavior' => 'inclusive',
            'paymentmethodconfiguration' => null,
            'allowpromotioncodes' => 0,
        ];
        $payable = new payable(10.50, 'USD', 123);
        $productpricingservice->expects($this->once())->method('create_product_and_price')
            ->with($config, $payable, 'Test Stripe Product', 10.50, 'enrol_fee', 'fee', '42')
            ->willReturn([Product::constructFrom(['id' => 'prod_test_1']), Price::constructFrom(['id' => 'price_test_1'])]);

        $service->generate_payment(
            $config,
            $payable,
            'Test Stripe Product',
            10.50,
            'enrol_fee',
            'fee',
            '42'
        );

        $this->assertSame('auto', $payload['billing_address_collection']);
        $this->assertFalse($payload['automatic_tax']['enabled']);
        $this->assertFalse($payload['invoice_creation']['enabled']);
        $this->assertFalse($payload['allow_promotion_codes']);
        $this->assertSame('pmc_default', $payload['payment_method_configuration']);
        $this->assertSame('cus_existing', $payload['customer']);
    }

    /**
     * Tests helpers reading checkout states from Stripe data.
     */
    public function test_session_status_helpers_read_from_stripe_retrieval(): void {
        $service = new checkout_service($this->client);

        $paid = Session::constructFrom([
            'id' => 'cs_paid',
            'object' => 'checkout.session',
            'mode' => 'payment',
            'payment_status' => 'paid',
            'payment_intent' => ['id' => 'pi_paid', 'object' => 'payment_intent', 'status' => 'succeeded'],
        ]);
        $processing = Session::constructFrom([
            'id' => 'cs_processing',
            'object' => 'checkout.session',
            'mode' => 'subscription',
            'payment_status' => 'unpaid',
            'payment_intent' => ['id' => 'pi_processing', 'object' => 'payment_intent', 'status' => 'processing'],
        ]);
        $calls = [
            ['cs_paid', null, $paid],
            ['cs_processing', null, $processing],
            ['cs_paid', null, $paid],
            ['cs_processing', null, $processing],
            ['cs_processing', ['expand' => ['payment_intent']], $processing],
            ['cs_paid', ['expand' => ['payment_intent']], $paid],
        ];
        $this->mock_sessions()->expects($this->exactly(6))->method('retrieve')
            ->willReturnCallback(function (string $id, ?array $params = null) use (&$calls): Session {
                [$expectedid, $expectedparams, $session] = array_shift($calls);
                $this->assertSame($expectedid, $id);
                $this->assertSame($expectedparams, $params);
                return $session;
            });

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

        $service = new checkout_service($this->client);
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

        $service = new checkout_service($this->client);

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

        $this->mock_sessions()->expects($this->once())->method('retrieve')
            ->with('cs_status_1', ['expand' => ['line_items', 'customer']])
            ->willReturn(checkout_test_session_factory::payment_session('cs_status_1', 'complete', 'paid'));
        $subscriptionservice = $this->createMock(subscription_service::class);
        $subscriptionservice->expects($this->never())->method('save_subscription');
        $service = new checkout_service($this->client);
        $this->set_private_property($service, 'subscriptionservice', $subscriptionservice);

        $service->save_payment_status('cs_status_1');

        $record = $DB->get_record('paygw_stripe_checkout_sessions', ['checkoutsessionid' => 'cs_status_1'], '*', MUST_EXIST);
        $this->assertSame('paid', $record->paymentstatus);
    }

    /**
     * Tests save_payment_status delegates subscription mode sessions to the subscription service.
     */
    public function test_save_payment_status_delegates_subscription_mode_session(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $session = checkout_test_session_factory::subscription_session('cs_status_sub');
        $this->mock_sessions()->expects($this->once())->method('retrieve')
            ->with('cs_status_sub', ['expand' => ['line_items', 'customer']])->willReturn($session);
        $subscriptionservice = $this->createMock(subscription_service::class);
        $subscriptionservice->expects($this->once())->method('save_subscription')->with($session);
        $service = new checkout_service($this->client);
        $this->set_private_property($service, 'subscriptionservice', $subscriptionservice);

        $service->save_payment_status('cs_status_sub');

        $this->assertFalse($DB->record_exists('paygw_stripe_checkout_sessions', ['checkoutsessionid' => 'cs_status_sub']));
    }

    /**
     * Register the nested checkout sessions SDK service.
     *
     * @return MockObject Sessions service mock
     */
    private function mock_sessions(): MockObject {
        $checkout = $this->mock_stripe_service('checkout', CheckoutServiceFactory::class);
        $sessions = $this->createMock(SessionService::class);
        $checkout->method('__get')->with('sessions')->willReturn($sessions);
        return $sessions;
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
