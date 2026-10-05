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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace paygw_stripe\local\service;

use advanced_testcase;
use core_payment\local\entities\payable;
use paygw_stripe\local\repository\invoice_repository;
use paygw_stripe\stripe_helper;
use paygw_stripe\tests\fixtures\invoice_http_client;
use Stripe\ApiRequestor;
use Stripe\Event;
use Stripe\StripeClient;

global $CFG;
require_once($CFG->dirroot . '/payment/gateway/stripe/.extlib/stripe-php/init.php');
require_once(__DIR__ . '/fixtures/invoice_http_client.php');

/**
 * Integration tests using Moodle's real database, payment callback and lock factory.
 *
 * @package paygw_stripe
 * @category test
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class invoice_flow_test extends advanced_testcase {
    private invoice_http_client $http;
    private invoice_service $service;
    private invoice_repository $repository;
    private int $accountid;
    private int $instanceid;
    private int $userid;
    private $originalhttpclient;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        if ($this->name() === 'test_invoice_delivery_failure_rolls_back_payment_and_retries') {
            // Moodle's test transaction otherwise encloses the service's delegated transaction.
            $this->preventResetByRollback();
        }
        $property = new \ReflectionProperty(ApiRequestor::class, '_httpClient');
        $property->setAccessible(true);
        $this->originalhttpclient = $property->getValue();
        $this->http = new invoice_http_client();
        ApiRequestor::setHttpClient($this->http);
        $this->service = new invoice_service(new StripeClient([
            'api_key' => 'sk_test_fake',
            'stripe_version' => stripe_helper::$apiversion,
        ]));
        $this->repository = new invoice_repository();
        $user = $this->getDataGenerator()->create_user(['email' => 'learner@example.test']);
        $this->setUser($user);
        $this->userid = (int)$user->id;
        $account = $this->getDataGenerator()->get_plugin_generator('core_payment')
            ->create_payment_account(['gateways' => 'stripe']);
        $this->accountid = (int)$account->get('id');
        $DB->set_field('payment_gateways', 'config',
            json_encode(['apikey' => 'pk_test_fake', 'secretkey' => 'sk_test_fake']),
            ['accountid' => $this->accountid, 'gateway' => 'stripe']);
        $course = $this->getDataGenerator()->create_course();
        $this->instanceid = (int)enrol_get_plugin('fee')->add_instance($course, [
            'courseid' => $course->id,
            'customint1' => $this->accountid,
            'cost' => 49.95,
            'currency' => 'EUR',
            'roleid' => (int)$DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST),
        ]);
    }

    protected function tearDown(): void {
        ApiRequestor::setHttpClient($this->originalhttpclient);
        parent::tearDown();
    }

    private function start(string $currency = 'EUR', ?string $country = null, bool $tax = false): array {
        $config = (object)['enableautomatictax' => $tax, 'defaulttaxbehavior' => 'exclusive'];
        if ($country !== null) {
            $config->invoicebankcountry = $country;
        }
        $url = $this->service->start_payment($config, new payable(49.95, $currency, $this->accountid),
            'Course fee', 49.95, 'enrol_fee', 'fee', $this->instanceid);
        $this->assertStringStartsWith('https://billing.stripe.com/', $url);
        $session = end($this->http->sessions);
        parse_str(parse_url($session['flow_data']['after_completion']['redirect']['return_url'], PHP_URL_QUERY), $query);
        return [(int)$query['request'], $query['token']];
    }

    private function billing_details(int $id): void {
        $customerid = $this->repository->find_by_id($id)->customerid;
        $this->http->customers[$customerid]['name'] = 'Example GmbH';
        $this->http->customers[$customerid]['email'] = 'billing@example.test';
        $this->http->customers[$customerid]['address'] = ['line1' => 'Billing Street', 'country' => 'DE'];
    }

    private function complete(int $id, string $token): string {
        $this->billing_details($id);
        $url = $this->service->complete_billing($id, $this->userid, $token);
        $invoiceid = $this->repository->find_by_id($id)->invoiceid;
        $this->assertSame('https://invoice.stripe.com/' . $invoiceid, $url);
        return $invoiceid;
    }

    private function event(string $invoiceid, string $type = 'invoice.paid'): Event {
        return Event::constructFrom(['id' => 'evt_test', 'object' => 'event', 'type' => $type,
            'data' => ['object' => $this->http->invoices[$invoiceid] ?? ['id' => $invoiceid, 'object' => 'invoice']]]);
    }

    private function assert_error(string $code, callable $callback): void {
        try {
            $callback();
            $this->fail('Expected ' . $code);
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
    }

    public function test_invoice_continuation_authorization_expiry_and_cancel(): void {
        global $DB;
        [$id, $token] = $this->start();
        $session = end($this->http->sessions);
        $this->assertStringNotContainsString($token, $session['return_url']);
        $this->assertSame('customer_update', $session['flow_data']['type']);
        $this->assertSame(['name', 'address', 'email', 'tax_id'],
            end($this->http->configs)['features']['customer_update']['allowed_updates']);
        $this->assertSame(0, $DB->count_records('payments'));
        $this->assertCount(0, $this->http->invoices);
        $this->assertSame(hash('sha256', $token), $this->repository->find_by_id($id)->tokenhash);

        $this->assert_error('invalidinvoicecontinuation',
            fn() => $this->service->complete_billing($id, $this->userid + 1, $token));
        $this->assert_error('invalidinvoicecontinuation',
            fn() => $this->service->complete_billing($id, $this->userid, str_repeat('a', 64)));
        $this->assert_error('invoicebillingincomplete',
            fn() => $this->service->complete_billing($id, $this->userid, $token));
        [$other, $othertoken] = $this->start();
        $this->assert_error('invalidinvoicecontinuation',
            fn() => $this->service->complete_billing($other, $this->userid, $token));
        $record = $this->repository->find_by_id($other);
        $record->timeexpires = time() - 1;
        $this->repository->save($record);
        $this->assert_error('invalidinvoicecontinuation',
            fn() => $this->service->complete_billing($other, $this->userid, $othertoken));
        $this->assertSame('expired', $this->repository->find_by_id($other)->status);
        $this->service->cancel_billing($id, $this->userid);
        $this->assert_error('invalidinvoicecontinuation',
            fn() => $this->service->complete_billing($id, $this->userid, $token));
        $this->assertSame('cancelled', $this->repository->find_by_id($id)->status);
        $this->assertCount(0, $this->http->invoices);
    }

    public function test_invoice_draft_item_finalize_email_and_idempotent_callback(): void {
        global $DB;
        [$id, $token] = $this->start();
        $invoiceid = $this->complete($id, $token);
        $invoice = $this->http->invoices[$invoiceid];
        $record = $this->repository->find_by_id($id);
        $this->assertSame('open', $invoice['status']);
        $this->assertSame('send_invoice', $invoice['collection_method']);
        $this->assertSame(14, $invoice['days_until_due']);
        $this->assertFalse($invoice['auto_advance']);
        $this->assertSame('exclude', $invoice['pending_invoice_items_behavior']);
        $this->assertSame($invoiceid, end($this->http->items)['invoice']);
        $this->assertSame(4995, end($this->http->items)['amount']);
        $this->assertSame((string)$id, $invoice['metadata']['transactionid']);
        $this->assertSame('sent', $record->emailstatus);
        $this->assertSame('ready', $record->paymentmethodstatus);
        $this->assertSame([['invoiceid' => $invoiceid, 'email' => 'billing@example.test',
            'key' => 'moodle-invoice-' . $record->tokenhash . '-send']], $this->http->sent);
        $this->assertSame(0, $DB->count_records('payments'));
        $this->assertSame('https://invoice.stripe.com/' . $invoiceid,
            $this->service->complete_billing($id, $this->userid, $token));
        $this->assertCount(1, $this->http->invoices);
        $this->assertCount(1, $this->http->items);
        $this->assertCount(1, $this->http->sent);
        foreach ($this->http->requests as $request) {
            $this->assertSame(stripe_helper::$apiversion, $request['version']);
        }
    }

    public function test_invoice_lost_responses_reuse_remote_writes(): void {
        global $DB;
        foreach (['create', 'item', 'finalize'] as $failure) {
            [$id, $token] = $this->start();
            $this->billing_details($id);
            $nextid = 'in_' . (count($this->http->invoices) + 1);
            $path = match ($failure) {
                'create' => '/v1/invoices',
                'item' => '/v1/invoiceitems',
                'finalize' => '/v1/invoices/' . $nextid . '/finalize',
            };
            $this->http->lose = $path;
            try {
                $this->service->complete_billing($id, $this->userid, $token);
                $this->fail('Expected lost Stripe response');
            } catch (\Stripe\Exception\ApiConnectionException $e) {
                $this->assertStringContainsString('response loss', $e->getMessage());
            }
            $this->complete($id, $token);
            $this->assertSame(0, $DB->count_records('payments'));
            $this->assertCount(count($this->http->invoices), $this->http->sent);
        }
    }

    public function test_invoice_signed_webhook_paid_void_duplicate_and_rollback(): void {
        global $DB;
        [$id, $token] = $this->start();
        $invoiceid = $this->complete($id, $token);
        $this->assertFalse($this->service->process_event($this->event('in_unknown')));
        $this->assertTrue($this->service->process_event($this->event($invoiceid)));
        $this->assertSame(0, $DB->count_records('payments'));
        $this->http->paid($invoiceid);
        $payload = json_encode($this->event($invoiceid)->toArray());
        $timestamp = time();
        $signature = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, 'whsec_fake');
        $handler = new invoice_webhook_handler();
        try {
            $handler->handle($payload, 't=' . $timestamp . ',v1=invalid');
            $this->fail('Forged signature accepted');
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            $this->assertSame(0, $DB->count_records('payments'));
        }
        $this->assertTrue($handler->handle($payload, $signature));
        $this->assertTrue($handler->handle($payload, $signature));
        $this->assertSame(1, $DB->count_records('payments'));
        $this->assertSame(1, $DB->count_records('user_enrolments', ['userid' => $this->userid]));
        $this->assertSame(49.95, (float)$DB->get_field('payments', 'amount',
            ['id' => $this->repository->find_by_id($id)->paymentid]));
        $this->assertTrue($this->repository->find_by_id($id)->delivered);
        $this->assertTrue($this->service->process_event($this->event($invoiceid, 'invoice.voided')));
        $this->assertTrue($this->repository->find_by_id($id)->delivered);
        $this->assertSame(1, $DB->count_records('user_enrolments', ['userid' => $this->userid]));

        [$other, $othertoken] = $this->start();
        $voidid = $this->complete($other, $othertoken);
        $this->http->invoices[$voidid]['status'] = 'void';
        $this->assertTrue($this->service->process_event($this->event($voidid, 'invoice.voided')));
        $this->assertTrue($this->service->process_event($this->event($voidid)));
        $this->assertSame('void', $this->repository->find_by_id($other)->status);
        $this->assertSame(1, $DB->count_records('payments'));
        $this->assert_error('invalidinvoicecontinuation',
            fn() => $this->service->complete_billing($other, $this->userid, $othertoken));
    }

    public function test_invoice_delivery_failure_rolls_back_payment_and_retries(): void {
        global $DB;
        [$id, $token] = $this->start();
        $invoiceid = $this->complete($id, $token);
        $this->http->paid($invoiceid);
        $DB->set_field('enrol', 'enrol', 'manual', ['id' => $this->instanceid]);
        try {
            $this->service->process_event($this->event($invoiceid));
            $this->fail('Delivery should have failed');
        } catch (\dml_missing_record_exception $e) {
            $this->assertSame(0, $DB->count_records('payments'));
            $this->assertFalse($this->repository->find_by_id($id)->delivered);
        }
        $DB->set_field('enrol', 'enrol', 'fee', ['id' => $this->instanceid]);
        $this->assertTrue($this->service->process_event($this->event($invoiceid)));
        $this->assertSame(1, $DB->count_records('payments'));
        $this->assertSame(1, $DB->count_records('user_enrolments', ['userid' => $this->userid]));
    }

    public function test_invoice_bank_countries_defaults_and_non_eur(): void {
        global $DB;
        [$id, $token] = $this->start('EUR', ' fr ');
        $this->http->invoicemethods = ['paypal', 'klarna'];
        $invoiceid = $this->complete($id, $token);
        $this->assertSame('FR', $this->repository->find_by_id($id)->banktransfercountry);
        $settings = $this->http->invoices[$invoiceid]['payment_settings'];
        $this->assertSame(['paypal', 'klarna', 'customer_balance'], $settings['payment_method_types']);
        $this->assertSame('FR', $settings['payment_method_options']['customer_balance']
            ['bank_transfer']['eu_bank_transfer']['country']);
        $creates = array_values(array_filter($this->http->requests,
            static fn($request) => $request['path'] === '/v1/invoices' && $request['method'] === 'post'));
        $this->assertArrayNotHasKey('payment_settings', $creates[0]['params']);
        $before = count($this->http->requests);
        $this->service->complete_billing($id, $this->userid, $token);
        $this->assertSame($before + 1, count($this->http->requests));
        [$other, $othertoken] = $this->start('JPY', 'AT');
        $otherinvoice = $this->complete($other, $othertoken);
        $this->assertNull($this->repository->find_by_id($other)->banktransfercountry);
        $this->assertArrayNotHasKey('payment_settings', $this->http->invoices[$otherinvoice]);
        $this->assert_error('invalidinvoicebankcountry', fn() => $this->start('EUR', 'AT'));
        $this->assertSame(2, $DB->count_records('paygw_stripe_invoices'));
    }

    public function test_invoice_email_failed_send_retries_once_and_old_uncertainty_stops(): void {
        [$id, $token] = $this->start();
        $this->billing_details($id);
        $this->http->lose = '/v1/invoices/in_1/send';
        $this->assert_error('invoiceemailfailed',
            fn() => $this->service->complete_billing($id, $this->userid, $token));
        $this->assertSame('sending', $this->repository->find_by_id($id)->emailstatus);
        $this->assertCount(1, $this->http->sent);
        $this->complete($id, $token);
        $this->assertCount(1, $this->http->sent);
        $this->assertSame('sent', $this->repository->find_by_id($id)->emailstatus);
        $sends = array_values(array_filter($this->http->requests,
            static fn($request) => $request['path'] === '/v1/invoices/in_1/send'));
        $this->assertSame($sends[0]['key'], $sends[1]['key']);

        [$other, $othertoken] = $this->start();
        $this->billing_details($other);
        $this->http->lose = '/v1/invoices/in_2/send';
        $this->assert_error('invoiceemailfailed',
            fn() => $this->service->complete_billing($other, $this->userid, $othertoken));
        $record = $this->repository->find_by_id($other);
        $record->timeemailstarted = time() - DAYSECS;
        $this->repository->save($record);
        $this->http->keys = [];
        $this->assert_error('invoiceemailrecoveryrequired',
            fn() => $this->service->complete_billing($other, $this->userid, $othertoken));
        $this->assertCount(2, $this->http->sent);
    }

    public function test_invoice_uncertain_old_creation_does_not_create_another_invoice(): void {
        [$id, $token] = $this->start();
        $this->billing_details($id);
        $this->http->lose = '/v1/invoices';
        try {
            $this->service->complete_billing($id, $this->userid, $token);
            $this->fail('Expected lost response');
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            $this->assertCount(1, $this->http->invoices);
        }
        $record = $this->repository->find_by_id($id);
        $record->timeconfirmed = time() - DAYSECS;
        $this->repository->save($record);
        $this->http->keys = [];
        $this->assert_error('invoicerecoveryrequired',
            fn() => $this->service->complete_billing($id, $this->userid, $token));
        $this->assertCount(1, $this->http->invoices);
        $this->assertCount(0, $this->http->items);
    }

    public function test_invoice_missing_stripe_defaults_blocks_email_then_retry_recovers(): void {
        [$id, $token] = $this->start();
        $this->billing_details($id);
        $this->http->failbefore = '/v1/invoice_payments';
        $this->assert_error('invoicepaymentmethodsfailed',
            fn() => $this->service->complete_billing($id, $this->userid, $token));
        $this->assertSame('pending', $this->repository->find_by_id($id)->paymentmethodstatus);
        $this->assertCount(0, $this->http->sent);
        $payment = $this->http->invoicepayments['in_1'];
        $this->http->invoicepayments = [];
        $this->assert_error('invoicepaymentmethodsfailed',
            fn() => $this->service->complete_billing($id, $this->userid, $token));
        $this->http->invoicepayments['in_1'] = $payment;
        $this->http->expandintents = false;
        $this->complete($id, $token);
        $this->assertCount(1, $this->http->sent);
        $this->assertNotEmpty(array_filter($this->http->requests,
            static fn($request) => $request['path'] === '/v1/payment_intents/pi_in_1'));
    }

    public function test_invoice_binding_rejects_altered_stripe_invoice(): void {
        global $DB;
        [$id, $token] = $this->start();
        $invoiceid = $this->complete($id, $token);
        $this->http->paid($invoiceid);
        foreach (['customer' => 'cus_foreign', 'currency' => 'usd', 'total' => 1] as $field => $value) {
            $original = $this->http->invoices[$invoiceid][$field];
            $this->http->invoices[$invoiceid][$field] = $value;
            $this->assert_error('invalidinvoicebinding',
                fn() => $this->service->process_event($this->event($invoiceid)));
            $this->http->invoices[$invoiceid][$field] = $original;
        }
        $this->http->invoices[$invoiceid]['metadata']['userid'] = '999';
        $this->assert_error('invalidinvoicebinding',
            fn() => $this->service->process_event($this->event($invoiceid)));
        $this->assertSame(0, $DB->count_records('payments'));
    }

    public function test_invoice_customer_identity_and_portal_configuration_are_preserved(): void {
        global $DB, $USER;
        [$id] = $this->start();
        $this->billing_details($id);
        $record = $this->repository->find_by_id($id);
        $customer = (new customer_service(new StripeClient(['api_key' => 'sk_test_fake'])))->get_customer($this->userid);
        $this->assertSame('Example GmbH', $customer->name);
        (new customer_service(new StripeClient(['api_key' => 'sk_test_fake'])))
            ->update_customer_details($customer, $USER);
        $this->assertSame('Example GmbH', $this->http->customers[$record->customerid]['name']);
        $this->assertSame('billing@example.test', $this->http->customers[$record->customerid]['email']);
        $this->http->customererror = true;
        try {
            (new customer_service(new StripeClient(['api_key' => 'sk_test_fake'])))->get_customer($this->userid);
            $this->fail('Expected customer outage');
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            $this->assertSame(1, $DB->count_records('paygw_stripe_customers'));
        }
        $this->http->customererror = false;
        $this->http->configs['bpc_1']['features']['payment_method_update']['enabled'] = true;
        $this->start();
        $this->assertCount(1, $this->http->configs);
        $this->assertFalse($this->http->configs['bpc_1']['features']['payment_method_update']['enabled']);
        $this->http->configs['bpc_1']['is_default'] = true;
        $this->start();
        $this->assertCount(2, $this->http->configs);
        $this->assertSame('bpc_2', end($this->http->sessions)['configuration']);
    }

    public function test_invoice_legacy_purchase_keeps_old_request_parameters_and_skips_email(): void {
        global $DB;
        [$id, $token] = $this->start();
        $record = $this->repository->find_by_id($id);
        $record->emailstatus = 'legacy';
        $record->banktransfercountry = null;
        $record->paymentmethodstatus = 'legacy';
        $this->repository->save($record);
        $invoiceid = $this->complete($id, $token);
        $this->assertArrayNotHasKey('payment_settings', $this->http->invoices[$invoiceid]);
        $this->assertSame('legacy', $this->repository->find_by_id($id)->emailstatus);
        $this->assertCount(0, $this->http->sent);
        $this->http->paid($invoiceid);
        $this->assertTrue($this->service->process_event($this->event($invoiceid)));
        $this->assertSame(1, $DB->count_records('payments'));
    }

    public function test_invoice_credit_balance_waits_for_webhook_and_records_jpy_without_conversion(): void {
        global $DB;
        $this->http->autopaid = true;
        [$id, $token] = $this->start('JPY');
        $invoiceid = $this->complete($id, $token);
        $this->assertSame('paid', $this->http->invoices[$invoiceid]['status']);
        $this->assertCount(1, $this->http->sent);
        $this->assertSame(0, $DB->count_records('payments'));
        $this->assertTrue($this->service->process_event($this->event($invoiceid)));
        $payment = $DB->get_record('payments', ['id' => $this->repository->find_by_id($id)->paymentid], '*', MUST_EXIST);
        $this->assertSame(50.0, (float)$payment->amount);
        $this->assertSame('JPY', $payment->currency);
    }

}
