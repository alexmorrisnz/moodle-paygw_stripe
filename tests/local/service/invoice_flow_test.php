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

declare(strict_types=1);

namespace paygw_stripe\local\service;

defined('MOODLE_INTERNAL') || die();

use core_payment\local\entities\payable;
use paygw_stripe\local\repository\invoice_repository;
use paygw_stripe\stripe_helper;
use paygw_stripe\tests\fixtures\invoice_testcase;
use Stripe\Event;

require_once(__DIR__ . '/../../fixtures/invoice_testcase.php');

/**
 * Integration tests using Moodle's real database, payment callback and lock factory.
 *
 * @package paygw_stripe
 * @category test
 * @covers \paygw_stripe\local\service\invoice_service
 * @covers \paygw_stripe\local\service\customer_service
 * @covers \paygw_stripe\stripe_helper
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class invoice_flow_test extends invoice_testcase {
    /** @var invoice_service Service under test. */
    private invoice_service $service;
    /** @var invoice_repository Persisted invoice requests. */
    private invoice_repository $repository;
    /** @var int Payment account ID. */
    private int $accountid;
    /** @var int Fee enrolment instance ID. */
    private int $instanceid;
    /** @var int Purchasing user ID. */
    private int $userid;

    /**
     * Create a purchasing user, payment account and fee enrolment instance.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->service = new invoice_service($this->client);
        $this->repository = new invoice_repository();
        $user = $this->getDataGenerator()->create_user(['email' => 'learner@example.test']);
        $this->setUser($user);
        $this->userid = (int)$user->id;
        $account = $this->getDataGenerator()->get_plugin_generator('core_payment')
            ->create_payment_account(['gateways' => 'stripe']);
        $this->accountid = (int)$account->get('id');
        $DB->set_field(
            'payment_gateways',
            'config',
            json_encode(['apikey' => 'pk_test_fake', 'secretkey' => 'sk_test_fake']),
            ['accountid' => $this->accountid, 'gateway' => 'stripe']
        );
        $this->instanceid = $this->create_fee_instance();
    }

    /**
     * Create a distinct fee purchase target using the same payment account.
     *
     * @return int Fee enrolment instance ID
     */
    private function create_fee_instance(): int {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        return (int)enrol_get_plugin('fee')->add_instance($course, [
            'courseid' => $course->id,
            'customint1' => $this->accountid,
            'cost' => 49.95,
            'currency' => 'EUR',
            'roleid' => (int)$DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST),
        ]);
    }

    /**
     * Start an invoice purchase and extract its continuation credentials.
     *
     * @param string $currency Payment currency
     * @param string|null $country Bank transfer country
     * @param bool $tax Whether to enable automatic tax
     * @param int|null $itemid Purchase item ID
     * @return array Invoice request ID and continuation token
     */
    private function start(
        string $currency = 'EUR',
        ?string $country = null,
        bool $tax = false,
        ?int $itemid = null
    ): array {
        $url = $this->start_url($currency, $country, $tax, $itemid);
        $this->assertStringStartsWith('https://billing.stripe.com/', $url);
        $session = end($this->http->sessions);
        parse_str(parse_url($session['flow_data']['after_completion']['redirect']['return_url'], PHP_URL_QUERY), $query);
        return [(int)$query['request'], $query['token']];
    }

    /**
     * Start or resume a purchase and return its redirect URL.
     *
     * @param string $currency
     * @param string|null $country
     * @param bool $tax
     * @param int|null $itemid
     * @return string
     */
    private function start_url(
        string $currency = 'EUR',
        ?string $country = null,
        bool $tax = false,
        ?int $itemid = null
    ): string {
        $config = (object)['enableautomatictax' => $tax, 'defaulttaxbehavior' => 'exclusive'];
        if ($country !== null) {
            $config->invoicebankcountry = $country;
        }
        return $this->service->start_payment(
            $config,
            new payable(49.95, $currency, $this->accountid),
            'Course fee',
            49.95,
            'enrol_fee',
            'fee',
            $itemid ?? $this->instanceid
        );
    }

    /**
     * Populate the customer's billing details in the API fixture.
     *
     * @param int $id Invoice request ID
     */
    private function billing_details(int $id): void {
        $customerid = $this->repository->find_by_id($id)->customerid;
        $this->http->customers[$customerid]['name'] = 'Example GmbH';
        $this->http->customers[$customerid]['email'] = 'billing@example.test';
        $this->http->customers[$customerid]['address'] = ['line1' => 'Billing Street', 'country' => 'DE'];
    }

    public function test_returning_purchase_reopens_invoice_but_unfinished_billing_is_rejected(): void {
        global $DB;
        [$id, $token] = $this->start();
        $sessioncount = count($this->http->sessions);

        $this->assert_error('invoicealreadyactive', fn() => $this->start_url());
        $this->assertSame($sessioncount, count($this->http->sessions));
        $this->assertSame(1, $DB->count_records('paygw_stripe_invoices'));

        $invoiceid = $this->complete($id, $token);
        $this->assertSame('open', $this->http->invoices[$invoiceid]['status']);
        $record = $this->repository->find_by_id($id);
        $record->timeexpires = time() - DAYSECS;
        $this->repository->save($record);
        $this->assertSame('https://invoice.stripe.com/' . $invoiceid, $this->start_url());
        $this->assertSame($sessioncount, count($this->http->sessions));
        $this->assertCount(1, $this->http->invoices);
        $this->assertCount(1, $this->http->sent);

        [$other] = $this->start(itemid: $this->create_fee_instance());
        $this->assertNotSame($id, $other);
        $this->assertSame(2, $DB->count_records('paygw_stripe_invoices'));
    }

    public function test_returning_purchase_checks_stripe_status_and_waits_for_paid_webhook(): void {
        global $DB;
        [$id, $token] = $this->start();
        $invoiceid = $this->complete($id, $token);
        $this->http->paid($invoiceid);

        $this->assertSame('https://invoice.stripe.com/' . $invoiceid, $this->start_url());
        $this->assertSame('paid', $this->repository->find_by_id($id)->status);
        $this->assertSame('https://invoice.stripe.com/' . $invoiceid, $this->start_url());
        $this->assertSame(1, $DB->count_records('paygw_stripe_invoices'));
        $this->assertSame(0, $DB->count_records('payments'));
        $this->assertFalse($this->repository->find_by_id($id)->delivered);
        $this->assertTrue($this->service->process_event($this->event($invoiceid)));
    }

    public function test_returning_purchase_can_replace_a_void_invoice(): void {
        [$id, $token] = $this->start();
        $invoiceid = $this->complete($id, $token);
        $this->http->invoices[$invoiceid]['status'] = 'void';
        [$other] = $this->start();
        $this->assertNotSame($id, $other);
        $this->assertSame('void', $this->repository->find_by_id($id)->status);
    }

    public function test_returning_purchase_rejects_a_foreign_invoice(): void {
        [$id, $token] = $this->start();
        $invoiceid = $this->complete($id, $token);
        $this->http->invoices[$invoiceid]['metadata']['userid'] = '999';
        $this->assert_error('invalidinvoicebinding', fn() => $this->start_url());
        $this->assertCount(1, $this->http->invoices);
        $this->assertCount(1, $this->http->sessions);
    }

    public function test_returning_purchase_does_not_create_a_duplicate_when_stripe_is_unavailable(): void {
        global $DB;
        [$id, $token] = $this->start();
        $invoiceid = $this->complete($id, $token);
        $this->http->failbefore = '/v1/invoices/' . $invoiceid;
        try {
            $this->start_url();
            $this->fail('Expected invoice retrieval failure');
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            $this->assertSame(1, $DB->count_records('paygw_stripe_invoices'));
            $this->assertCount(1, $this->http->sessions);
            $this->assertCount(1, $this->http->invoices);
        }
        $this->assertSame('https://invoice.stripe.com/' . $invoiceid, $this->start_url());
    }

    public function test_returning_purchase_finishes_preparing_the_same_invoice_after_email_failure(): void {
        [$id, $token] = $this->start();
        $this->billing_details($id);
        $this->http->failbefore = '/v1/invoices/in_1/send';
        $this->assert_error('invoiceemailfailed', fn() => $this->service->complete_billing($id, $this->userid, $token));

        $this->assertSame('https://invoice.stripe.com/in_1', $this->start_url());
        $this->assertSame('sent', $this->repository->find_by_id($id)->emailstatus);
        $this->assertCount(1, $this->http->invoices);
        $this->assertCount(1, $this->http->sent);
        $this->assertCount(1, $this->http->sessions);
    }

    /**
     * Complete billing and assert the hosted invoice URL.
     *
     * @param int $id Invoice request ID
     * @param string $token Continuation token
     * @return string Stripe invoice ID
     */
    private function complete(int $id, string $token): string {
        $this->billing_details($id);
        $url = $this->service->complete_billing($id, $this->userid, $token);
        $invoiceid = $this->repository->find_by_id($id)->invoiceid;
        $this->assertSame('https://invoice.stripe.com/' . $invoiceid, $url);
        return $invoiceid;
    }

    /**
     * Construct a webhook event from a fixture invoice.
     *
     * @param string $invoiceid Stripe invoice ID
     * @param string $type Event type
     * @return Event Stripe webhook event
     */
    private function event(string $invoiceid, string $type = 'invoice.paid'): Event {
        return Event::constructFrom(['id' => 'evt_test', 'object' => 'event', 'type' => $type,
            'data' => ['object' => $this->http->invoices[$invoiceid] ?? ['id' => $invoiceid, 'object' => 'invoice']]]);
    }

    /**
     * Assert that a callback throws the expected Moodle error.
     *
     * @param string $code Expected error code
     * @param callable $callback Operation to execute
     */
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
        [$id, $token] = $this->start(itemid: $this->create_fee_instance());
        $session = end($this->http->sessions);
        $this->assertStringNotContainsString($token, $session['return_url']);
        $this->assertSame('customer_update', $session['flow_data']['type']);
        $this->assertSame(
            ['name', 'address', 'email', 'tax_id'],
            end($this->http->configs)['features']['customer_update']['allowed_updates']
        );
        $this->assertSame(0, $DB->count_records('payments'));
        $this->assertCount(0, $this->http->invoices);
        $this->assertSame(hash('sha256', $token), $this->repository->find_by_id($id)->tokenhash);

        $this->assert_error(
            'invalidinvoicecontinuation',
            fn() => $this->service->complete_billing($id, $this->userid + 1, $token)
        );
        $this->assert_error(
            'invalidinvoicecontinuation',
            fn() => $this->service->complete_billing($id, $this->userid, str_repeat('a', 64))
        );
        $this->assert_error(
            'invoicebillingincomplete',
            fn() => $this->service->complete_billing($id, $this->userid, $token)
        );
        [$other, $othertoken] = $this->start(itemid: $this->create_fee_instance());
        $this->assert_error(
            'invalidinvoicecontinuation',
            fn() => $this->service->complete_billing($other, $this->userid, $token)
        );
        $record = $this->repository->find_by_id($other);
        $record->timeexpires = time() - 1;
        $this->repository->save($record);
        $this->assert_error(
            'invalidinvoicecontinuation',
            fn() => $this->service->complete_billing($other, $this->userid, $othertoken)
        );
        $this->assertSame('expired', $this->repository->find_by_id($other)->status);
        $this->service->cancel_billing($id, $this->userid);
        $this->assert_error(
            'invalidinvoicecontinuation',
            fn() => $this->service->complete_billing($id, $this->userid, $token)
        );
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
        $this->assertSame(
            'https://invoice.stripe.com/' . $invoiceid,
            $this->service->complete_billing($id, $this->userid, $token)
        );
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
            [$id, $token] = $this->start(itemid: $this->create_fee_instance());
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
            $this->assertEquals(1, $DB->get_field('paygw_stripe_customers', 'billingmanaged', ['userid' => $this->userid]));
            $this->complete($id, $token);
            $this->assertEquals(0, $DB->get_field('paygw_stripe_customers', 'billingmanaged', ['userid' => $this->userid]));
            $this->assertSame(0, $DB->count_records('payments'));
            $this->assertCount(count($this->http->invoices), $this->http->sent);
        }
    }

    public function test_failed_finalization_keeps_billing_identity_protected(): void {
        global $DB, $USER;

        [$id, $token] = $this->start();
        $this->billing_details($id);
        $this->http->failbefore = '/v1/invoices/in_1/finalize';
        try {
            $this->service->complete_billing($id, $this->userid, $token);
            $this->fail('Expected finalization failure');
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            $this->assertSame('draft', $this->http->invoices['in_1']['status']);
        }
        $this->assertEquals(1, $DB->get_field('paygw_stripe_customers', 'billingmanaged', ['userid' => $this->userid]));
        $customers = new customer_service($this->client);
        $customers->update_customer_details($customers->get_customer($this->userid), $USER);
        $customerid = $this->repository->find_by_id($id)->customerid;
        $this->assertSame('billing@example.test', $this->http->customers[$customerid]['email']);

        $this->service->complete_billing($id, $this->userid, $token);
        $this->assertEquals(0, $DB->get_field('paygw_stripe_customers', 'billingmanaged', ['userid' => $this->userid]));
    }

    public function test_finalized_invoice_releases_identity_before_email_retry(): void {
        global $DB, $USER;

        [$id, $token] = $this->start();
        $this->billing_details($id);
        $this->http->failbefore = '/v1/invoices/in_1/send';
        $this->assert_error('invoiceemailfailed', fn() => $this->service->complete_billing($id, $this->userid, $token));
        $this->assertEquals(0, $DB->get_field('paygw_stripe_customers', 'billingmanaged', ['userid' => $this->userid]));
        $this->assertSame('open', $this->http->invoices['in_1']['status']);

        $customers = new customer_service($this->client);
        $customers->update_customer_details($customers->get_customer($this->userid), $USER);
        $customerid = $this->repository->find_by_id($id)->customerid;
        $this->assertSame(fullname($USER), $this->http->customers[$customerid]['name']);
        $this->assertSame($USER->email, $this->http->customers[$customerid]['email']);
        $this->assertSame('billing@example.test', $this->http->invoices['in_1']['customer_email']);

        $this->service->complete_billing($id, $this->userid, $token);
        $this->assertSame('billing@example.test', $this->http->sent[0]['email']);
    }

    public function test_cancelled_and_expired_billing_release_identity(): void {
        global $DB;

        [$id] = $this->start();
        $this->assertEquals(1, $DB->get_field('paygw_stripe_customers', 'billingmanaged', ['userid' => $this->userid]));
        $this->service->cancel_billing($id, $this->userid);
        $this->assertEquals(0, $DB->get_field('paygw_stripe_customers', 'billingmanaged', ['userid' => $this->userid]));

        [$id, $token] = $this->start();
        $this->assertEquals(1, $DB->get_field('paygw_stripe_customers', 'billingmanaged', ['userid' => $this->userid]));
        $record = $this->repository->find_by_id($id);
        $record->timeexpires = time() - 1;
        $this->repository->save($record);
        $this->assert_error('invalidinvoicecontinuation', fn() => $this->service->complete_billing($id, $this->userid, $token));
        $this->assertEquals(0, $DB->get_field('paygw_stripe_customers', 'billingmanaged', ['userid' => $this->userid]));
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
        $this->assertSame(49.95, (float)$DB->get_field(
            'payments',
            'amount',
            ['id' => $this->repository->find_by_id($id)->paymentid]
        ));
        $this->assertTrue($this->repository->find_by_id($id)->delivered);
        $this->assertTrue($this->service->process_event($this->event($invoiceid, 'invoice.voided')));
        $this->assertTrue($this->repository->find_by_id($id)->delivered);
        $this->assertSame(1, $DB->count_records('user_enrolments', ['userid' => $this->userid]));

        [$other, $othertoken] = $this->start(itemid: $this->create_fee_instance());
        $voidid = $this->complete($other, $othertoken);
        $this->http->invoices[$voidid]['status'] = 'void';
        $this->assertTrue($this->service->process_event($this->event($voidid, 'invoice.voided')));
        $this->assertTrue($this->service->process_event($this->event($voidid)));
        $this->assertSame('void', $this->repository->find_by_id($other)->status);
        $this->assertSame(1, $DB->count_records('payments'));
        $this->assert_error(
            'invalidinvoicecontinuation',
            fn() => $this->service->complete_billing($other, $this->userid, $othertoken)
        );
    }

    public function test_invoice_delivery_failure_rolls_back_payment_and_retries(): void {
        global $DB;
        // Moodle's test transaction otherwise encloses the service's delegated transaction.
        $this->preventResetByRollback();
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
        $this->assertSame(
            'FR',
            $settings['payment_method_options']['customer_balance']['bank_transfer']['eu_bank_transfer']['country']
        );
        $creates = array_values(array_filter(
            $this->http->requests,
            static fn($request) => $request['path'] === '/v1/invoices' && $request['method'] === 'post'
        ));
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
        $this->assert_error(
            'invoiceemailfailed',
            fn() => $this->service->complete_billing($id, $this->userid, $token)
        );
        $this->assertSame('sending', $this->repository->find_by_id($id)->emailstatus);
        $this->assertCount(1, $this->http->sent);
        $this->complete($id, $token);
        $this->assertCount(1, $this->http->sent);
        $this->assertSame('sent', $this->repository->find_by_id($id)->emailstatus);
        $sends = array_values(array_filter(
            $this->http->requests,
            static fn($request) => $request['path'] === '/v1/invoices/in_1/send'
        ));
        $this->assertSame($sends[0]['key'], $sends[1]['key']);

        [$other, $othertoken] = $this->start(itemid: $this->create_fee_instance());
        $this->billing_details($other);
        $this->http->lose = '/v1/invoices/in_2/send';
        $this->assert_error(
            'invoiceemailfailed',
            fn() => $this->service->complete_billing($other, $this->userid, $othertoken)
        );
        $record = $this->repository->find_by_id($other);
        $record->timeemailstarted = time() - DAYSECS;
        $this->repository->save($record);
        $this->http->keys = [];
        $this->assert_error(
            'invoiceemailrecoveryrequired',
            fn() => $this->service->complete_billing($other, $this->userid, $othertoken)
        );
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
        $this->assert_error(
            'invoicerecoveryrequired',
            fn() => $this->service->complete_billing($id, $this->userid, $token)
        );
        $this->assertCount(1, $this->http->invoices);
        $this->assertCount(0, $this->http->items);
    }

    public function test_invoice_missing_stripe_defaults_blocks_email_then_retry_recovers(): void {
        [$id, $token] = $this->start();
        $this->billing_details($id);
        $this->http->failbefore = '/v1/invoice_payments';
        $this->assert_error(
            'invoicepaymentmethodsfailed',
            fn() => $this->service->complete_billing($id, $this->userid, $token)
        );
        $this->assertSame('pending', $this->repository->find_by_id($id)->paymentmethodstatus);
        $this->assertCount(0, $this->http->sent);
        $payment = $this->http->invoicepayments['in_1'];
        $this->http->invoicepayments = [];
        $this->assert_error(
            'invoicepaymentmethodsfailed',
            fn() => $this->service->complete_billing($id, $this->userid, $token)
        );
        $this->http->invoicepayments['in_1'] = $payment;
        $this->http->expandintents = false;
        $this->complete($id, $token);
        $this->assertCount(1, $this->http->sent);
        $this->assertNotEmpty(array_filter(
            $this->http->requests,
            static fn($request) => $request['path'] === '/v1/payment_intents/pi_in_1'
        ));
    }

    public function test_invoice_binding_rejects_altered_stripe_invoice(): void {
        global $DB;
        [$id, $token] = $this->start();
        $invoiceid = $this->complete($id, $token);
        $this->http->paid($invoiceid);
        foreach (['customer' => 'cus_foreign', 'currency' => 'usd', 'total' => 1] as $field => $value) {
            $original = $this->http->invoices[$invoiceid][$field];
            $this->http->invoices[$invoiceid][$field] = $value;
            $this->assert_error(
                'invalidinvoicebinding',
                fn() => $this->service->process_event($this->event($invoiceid))
            );
            $this->http->invoices[$invoiceid][$field] = $original;
        }
        $this->http->invoices[$invoiceid]['metadata']['userid'] = '999';
        $this->assert_error(
            'invalidinvoicebinding',
            fn() => $this->service->process_event($this->event($invoiceid))
        );
        $this->assertSame(0, $DB->count_records('payments'));
    }

    public function test_invoice_customer_identity_and_portal_configuration_are_preserved(): void {
        global $DB, $USER;
        [$id] = $this->start();
        $this->billing_details($id);
        $record = $this->repository->find_by_id($id);
        $customer = (new customer_service($this->client))->get_customer($this->userid);
        $this->assertSame('Example GmbH', $customer->name);
        (new customer_service($this->client))
            ->update_customer_details($customer, $USER);
        $this->assertSame('Example GmbH', $this->http->customers[$record->customerid]['name']);
        $this->assertSame('billing@example.test', $this->http->customers[$record->customerid]['email']);
        $this->http->customererror = true;
        try {
            (new customer_service($this->client))->get_customer($this->userid);
            $this->fail('Expected customer outage');
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            $this->assertSame(1, $DB->count_records('paygw_stripe_customers'));
        }
        $this->http->customererror = false;
        $this->http->configs['bpc_1']['features']['payment_method_update']['enabled'] = true;
        $this->start(itemid: $this->create_fee_instance());
        $this->assertCount(1, $this->http->configs);
        $this->assertFalse($this->http->configs['bpc_1']['features']['payment_method_update']['enabled']);
        $this->http->configs['bpc_1']['is_default'] = true;
        $this->start(itemid: $this->create_fee_instance());
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
        $this->assertEquals(0, $DB->get_field('paygw_stripe_customers', 'billingmanaged', ['userid' => $this->userid]));
        $this->assertCount(1, $this->http->sent);
        $this->assertSame(0, $DB->count_records('payments'));
        $this->assertTrue($this->service->process_event($this->event($invoiceid)));
        $payment = $DB->get_record('payments', ['id' => $this->repository->find_by_id($id)->paymentid], '*', MUST_EXIST);
        $this->assertSame(50.0, (float)$payment->amount);
        $this->assertSame('JPY', $payment->currency);
    }
}
