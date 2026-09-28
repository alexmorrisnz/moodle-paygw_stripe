<?php
// phpcs:ignoreFile -- Isolated CLI fixtures deliberately mirror external interfaces.
// GPL v3 or later. Run: php tests/standalone/run.php (PHP 8.1+, pdo_sqlite, simplexml, mbstring).
// This harness tests real plugin services/repositories and the bundled Stripe SDK.
// Only Moodle infrastructure and Stripe's remote API are replaced with test adapters.

require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/fake_stripe.php');

use core_payment\helper;
use core_payment\local\entities\payable;
use paygw_stripe\local\repository\invoice_repository;
use paygw_stripe\local\service\customer_service;
use paygw_stripe\local\service\invoice_service;
use paygw_stripe\local\service\invoice_webhook_handler;
use Stripe\Event;
use Stripe\StripeClient;

$testroot = sys_get_temp_dir() . '/moodle-invoice-tests-' . bin2hex(random_bytes(6));
mkdir($testroot, 0700, true);
$passed = 0;
$assertions = 0;
$CFG = (object)['wwwroot' => 'https://moodle.example.test', 'sessiontimeout' => 7200];
$USER = (object)['id' => 42, 'email' => 'learner@example.test', 'firstname' => 'Moodle', 'lastname' => 'Profile', 'lang' => 'de'];

function check($condition, $message = 'Assertion failed'): void {
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException($message); }
}
function rejects(callable $call, string $message): void {
    try { $call(); } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $message), 'Wrong error: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('Expected error: ' . $message);
}
function test(string $name, callable $run): void {
    global $passed;
    try { $run(); $passed++; echo "PASS $name\n"; }
    catch (Throwable $e) { fwrite(STDERR, "FAIL $name: " . $e . "\n"); exit(1); }
}
function fresh(): invoice_service {
    global $testroot, $testdir, $DB, $api, $client;
    $testdir = $testroot . '/' . bin2hex(random_bytes(4));
    mkdir($testdir);
    $DB = new moodle_database($testdir . '/test.sqlite');
    $DB->install();
    $api = new fake_stripe();
    \Stripe\ApiRequestor::setHttpClient($api);
    $client = new StripeClient(['api_key' => 'sk_test_fake', 'stripe_version' => '2026-07-29.dahlia']);
    helper::$failure = '';
    return new invoice_service($client);
}
function start(invoice_service $service, float $cost = 49.95, string $currency = 'EUR', bool $tax = false,
    ?string $bankcountry = null): array {
    global $api;
    $config = (object)['enableautomatictax' => $tax, 'defaulttaxbehavior' => 'exclusive'];
    if ($bankcountry !== null) { $config->invoicebankcountry = $bankcountry; }
    $url = $service->start_payment($config,
        new payable($cost, $currency, 7), 'Example course', $cost, 'enrol_fee', 'fee', 18);
    $session = end($api->sessions);
    parse_str(parse_url($session['flow_data']['after_completion']['redirect']['return_url'], PHP_URL_QUERY), $query);
    $id = (int)$query['request'];
    $token = $query['token'];
    check(str_starts_with($url, 'https://billing.stripe.com/'));
    return [$id, $token];
}
function save_details(int $id): void {
    global $api;
    $record = (new invoice_repository())->find_by_id($id);
    $api->customers[$record->customerid]['name'] = 'Example Company GmbH';
    $api->customers[$record->customerid]['email'] = 'billing@example.test';
    $api->customers[$record->customerid]['address'] = ['line1' => 'Billing Street 1', 'country' => 'DE'];
    $api->customers[$record->customerid]['tax_ids'] = ['object' => 'list', 'data' => [['id' => 'txi_1', 'object' => 'tax_id', 'type' => 'eu_vat', 'value' => 'DE123456789']], 'has_more' => false, 'url' => '/v1/test'];
}
function complete(invoice_service $s, int $id, string $token): string {
    save_details($id);
    $s->complete_billing($id, 42, $token);
    return (new invoice_repository())->find_by_id($id)->invoiceid;
}
function event(string $id, string $type = 'invoice.paid'): Event {
    global $api;
    return Event::constructFrom(['id' => 'evt_test', 'object' => 'event', 'type' => $type, 'data' => ['object' => $api->invoices[$id] ?? ['id' => $id, 'object' => 'invoice']]]);
}

test('Pending billing flow creates no invoice, uses restricted direct Portal and separate URLs', function() {
    global $api, $DB;
    $s = fresh(); [$id, $token] = start($s);
    check(count($api->invoices) === 0 && count($api->items) === 0 && $DB->count('payments') === 0);
    $record = (new invoice_repository())->find_by_id($id);
    check($record->status === 'billing' && $record->tokenhash === hash('sha256', $token));
    check($record->timeexpires - $record->timecreated === 3600);
    $session = end($api->sessions); $config = end($api->configs);
    check($session['flow_data']['type'] === 'customer_update');
    check(!str_contains($session['return_url'], $token));
    check(str_contains($session['return_url'], '/invoice_return.php?'));
    check($config['features']['customer_update']['allowed_updates'] === ['name', 'address', 'email', 'tax_id']);
    foreach (['invoice_history', 'payment_method_update', 'subscription_cancel', 'subscription_update'] as $feature) {
        check($config['features'][$feature]['enabled'] === false);
    }
    foreach ($api->requests as $r) {
        check($r['version'] === (str_contains($r['path'], 'billing_portal') ? '2026-08-26.dahlia' : '2026-07-29.dahlia'));
    }
});

test('Cancel, abandoned step, expired token, wrong user and crossed purchase cannot create invoices', function() {
    global $api;
    $s = fresh(); [$id, $token] = start($s);
    rejects(fn() => $s->complete_billing($id, 43, $token), 'invalidinvoicecontinuation');
    rejects(fn() => $s->complete_billing($id, 42, str_repeat('a', 64)), 'invalidinvoicecontinuation');
    [$other, $othertoken] = start($s);
    rejects(fn() => $s->complete_billing($other, 42, $token), 'invalidinvoicecontinuation');
    $repo = new invoice_repository(); $record = $repo->find_by_id($other);
    $record->timeexpires = time() - 1; $repo->save($record);
    rejects(fn() => $s->complete_billing($other, 42, $othertoken), 'invalidinvoicecontinuation');
    $s->cancel_billing($id, 42);
    rejects(fn() => $s->complete_billing($id, 42, $token), 'invalidinvoicecontinuation');
    check($repo->find_by_id($id)->status === 'cancelled');
    check($repo->find_by_id($other)->status === 'expired');
    check(count($api->invoices) === 0 && count($api->sent) === 0);
});

test('Successful callback requires billing details, creates one isolated draft/item, then finalizes without delivery', function() {
    global $api, $DB;
    $s = fresh(); [$id, $token] = start($s);
    rejects(fn() => $s->complete_billing($id, 42, $token), 'invoicebillingincomplete');
    $invoiceid = complete($s, $id, $token);
    $invoice = $api->invoices[$invoiceid]; $item = end($api->items);
    check($invoice['status'] === 'open' && $invoice['collection_method'] === 'send_invoice' && $invoice['days_until_due'] === 14);
    check($invoice['auto_advance'] === false && $invoice['pending_invoice_items_behavior'] === 'exclude');
    check($item['invoice'] === $invoiceid && $item['quantity'] === 1 && $item['amount'] === 4995);
    foreach (['userid' => '42', 'component' => 'enrol_fee', 'paymentarea' => 'fee', 'itemid' => '18', 'transactionid' => (string)$id] as $k => $v) {
        check($invoice['metadata'][$k] === $v);
    }
    $url = $s->complete_billing($id, 42, $token);
    check($url === 'https://invoice.stripe.com/' . $invoiceid);
    check(count($api->invoices) === 1 && count($api->items) === 1 && $DB->count('payments') === 0 && $DB->count('enrolments') === 0);
});

test('Repeated invoice.paid events create one Moodle payment and enrolment', function() {
    global $api, $DB;
    $s = fresh(); [$id, $token] = start($s); $invoiceid = complete($s, $id, $token);
    $api->paid($invoiceid);
    check($s->process_event(event($invoiceid)) && $s->process_event(event($invoiceid)));
    check($DB->count('payments') === 1 && $DB->count('enrolments') === 1);
    $record = (new invoice_repository())->find_by_id($id);
    $payment = $DB->get_record('payments', ['id' => $record->paymentid]);
    check($record->delivered && $record->status === 'paid');
    check((float)$payment->amount === 49.95 && $payment->currency === 'EUR');
});

test('Payment failure, thrown delivery failure and false delivery roll back and remain retryable', function() {
    global $api, $DB;
    foreach (['payment', 'delivery', 'false'] as $failure) {
        $s = fresh(); [$id, $token] = start($s); $invoiceid = complete($s, $id, $token); $api->paid($invoiceid);
        helper::$failure = $failure;
        rejects(fn() => $s->process_event(event($invoiceid)), $failure === 'false' ? 'invoicedeliveryfailed' : 'injected');
        check($DB->count('payments') === 0 && $DB->count('enrolments') === 0);
        check(!(new invoice_repository())->find_by_id($id)->delivered);
        helper::$failure = '';
        check($s->process_event(event($invoiceid)) && $DB->count('payments') === 1 && $DB->count('enrolments') === 1);
    }
});

test('Lost responses after invoice/item/finalize writes reuse Stripe objects on retry', function() {
    global $api, $DB;
    foreach (['/v1/invoices', '/v1/invoiceitems', '/v1/invoices/in_1/finalize'] as $path) {
        $s = fresh(); [$id, $token] = start($s); save_details($id); $api->lose = $path;
        rejects(fn() => $s->complete_billing($id, 42, $token), 'response loss');
        $s->complete_billing($id, 42, $token);
        check(count($api->invoices) === 1 && count($api->items) === 1 && $DB->count('payments') === 0);
    }
});

test('Do not reuse uncertain creation operations after Stripe idempotency retention', function() {
    global $api;
    $s = fresh(); [$id, $token] = start($s); save_details($id); $api->lose = '/v1/invoices';
    rejects(fn() => $s->complete_billing($id, 42, $token), 'response loss');
    $repo = new invoice_repository(); $record = $repo->find_by_id($id);
    $record->timeconfirmed = time() - 24 * HOURSECS; $repo->save($record);
    rejects(fn() => $s->complete_billing($id, 42, $token), 'invoicerecoveryrequired');
    check(count($api->invoices) === 1 && count($api->items) === 0);
});

test('Voided invoices do not enrol; a new purchase gets a new invoice; paid corrections preserve access', function() {
    global $api, $DB;
    $s = fresh(); [$id, $token] = start($s); $invoiceid = complete($s, $id, $token);
    $api->invoices[$invoiceid]['status'] = 'void';
    check($s->process_event(event($invoiceid, 'invoice.voided')));
    check((new invoice_repository())->find_by_id($id)->status === 'void' && $DB->count('payments') === 0);
    check($s->process_event(event($invoiceid)) && $DB->count('payments') === 0);
    rejects(fn() => $s->complete_billing($id, 42, $token), 'invalidinvoicecontinuation');
    [$newid, $newtoken] = start($s); $newinvoice = complete($s, $newid, $newtoken);
    check($newinvoice !== $invoiceid); $api->paid($newinvoice);
    $s->process_event(event($newinvoice));
    $s->process_event(event($newinvoice, 'invoice.voided'));
    check((new invoice_repository())->find_by_id($newid)->delivered && $DB->count('enrolments') === 1);
});

test('Unknown, unpaid, mismatched and replacement invoices cannot deliver a purchase', function() {
    global $api, $DB;
    $s = fresh(); [$id, $token] = start($s); $invoiceid = complete($s, $id, $token);
    check(!$s->process_event(event('in_unmanaged')));
    check(!$s->process_event(event($invoiceid, 'invoice.finalized')));
    check($s->process_event(event($invoiceid)) && $DB->count('payments') === 0);
    $api->paid($invoiceid);
    foreach (['customer' => 'cus_other', 'currency' => 'usd', 'total' => 5] as $field => $value) {
        $original = $api->invoices[$invoiceid][$field]; $api->invoices[$invoiceid][$field] = $value;
        rejects(fn() => $s->process_event(event($invoiceid)), 'invalidinvoicebinding');
        $api->invoices[$invoiceid][$field] = $original;
    }
    $api->invoices[$invoiceid]['metadata']['userid'] = '99';
    rejects(fn() => $s->process_event(event($invoiceid)), 'invalidinvoicebinding');
    check($DB->count('payments') === 0);
});

test('Billing identity survives later Checkout customer updates and transient Stripe errors', function() {
    global $api, $DB, $USER, $client;
    $s = fresh(); [$id, $token] = start($s); save_details($id);
    $customers = new customer_service($client); $customer = $customers->get_customer(42);
    $customers->update_customer_details($customer, $USER);
    check($api->customers[$customer->id]['name'] === 'Example Company GmbH');
    check($api->customers[$customer->id]['email'] === 'billing@example.test');
    $api->customererror = true;
    rejects(fn() => $customers->get_customer(42), 'outage');
    check($DB->count('paygw_stripe_customers') === 1);
    $api->customererror = false;
    $DB->update_record('paygw_stripe_customers', (object)['id' => 1, 'billingmanaged' => 0]);
    $customers->update_customer_details($customer, $USER);
    check($api->customers[$customer->id]['name'] === 'Moodle Profile');
});

test('Tax-inclusive payment totals and zero-decimal currencies are recorded from the invoice', function() {
    global $api, $DB;
    foreach ([['EUR', 49.95, 999, 59.94], ['JPY', 5000.0, 0, 5000.0]] as [$currency, $amount, $tax, $expected]) {
        $s = fresh(); [$id, $token] = start($s, $amount, $currency, (bool)$tax); $api->tax = $tax;
        $invoiceid = complete($s, $id, $token); $api->paid($invoiceid); $s->process_event(event($invoiceid));
        $record = (new invoice_repository())->find_by_id($id);
        check((float)$DB->get_record('payments', ['id' => $record->paymentid])->amount === $expected);
    }
});

test('Immediately paid credit-balance invoices still wait for invoice.paid before enrolment', function() {
    global $api, $DB;
    $s = fresh(); [$id, $token] = start($s); $api->autopaid = true;
    $invoiceid = complete($s, $id, $token);
    check($DB->count('payments') === 0 && !(new invoice_repository())->find_by_id($id)->delivered);
    check(count($api->sent) === 1);
    $s->process_event(event($invoiceid));
    check($DB->count('payments') === 1);
});

test('Existing webhook endpoint keeps subscriptions and gains invoice events', function() {
    global $api;
    $s = fresh(); start($s);
    $api->webhooks['we_1']['enabled_events'] = ['checkout.session.completed', 'customer.subscription.updated', 'custom.event'];
    start($s);
    check(count($api->webhooks) === 1);
    check($api->webhooks['we_1']['enabled_events'] === ['checkout.session.completed', 'customer.subscription.updated', 'custom.event', 'invoice.paid', 'invoice.voided']);
});

test('Invoice ID uniqueness permits multiple pending purchases but rejects duplicate invoice relationships', function() {
    $s = fresh(); [$id, $token] = start($s); [$other] = start($s); $invoiceid = complete($s, $id, $token);
    $repo = new invoice_repository(); $record = $repo->find_by_id($other); $record->invoiceid = $invoiceid;
    rejects(fn() => $repo->save($record), 'UNIQUE constraint failed');
});

test('Signed webhook routing verifies the original account secret, rejects forged and stale signatures', function() {
    global $api, $DB;
    $s = fresh(); [$id, $token] = start($s); $invoiceid = complete($s, $id, $token); $api->paid($invoiceid);
    $payload = json_encode(event($invoiceid)->toArray()); $timestamp = time();
    $handler = new invoice_webhook_handler();
    $sig = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, 'whsec_fake');
    rejects(fn() => $handler->handle($payload, 't=' . $timestamp . ',v1=bad'), 'No signatures found');
    $stale = $timestamp - 1000;
    rejects(fn() => $handler->handle($payload, 't=' . $stale . ',v1=' . hash_hmac('sha256', $stale . '.' . $payload, 'whsec_fake')), 'Timestamp outside');
    check($DB->count('payments') === 0);
    check($handler->handle($payload, $sig) && $handler->handle($payload, $sig) && $DB->count('payments') === 1);
});

test('A reused price with incompatible immutable tax behaviour gets an appropriate invoice price', function() {
    global $api;
    $s = fresh(); [$id, $token] = start($s, 49.95, 'EUR', true);
    complete($s, $id, $token);
    $api->prices['price_1']['tax_behavior'] = 'inclusive';
    [$other, $othertoken] = start($s, 49.95, 'EUR', true);
    complete($s, $other, $othertoken);
    $record = (new invoice_repository())->find_by_id($other);
    check($record->priceid !== 'price_1');
    check($api->prices[$record->priceid]['tax_behavior'] === 'exclusive');
    check($api->prices['price_1']['tax_behavior'] === 'inclusive');
});

test('Portal configuration is reused and restricted again; a default configuration is left untouched', function() {
    global $api;
    $s = fresh(); start($s);
    $api->configs['bpc_1']['features']['payment_method_update']['enabled'] = true;
    start($s);
    check(count($api->configs) === 1);
    check($api->configs['bpc_1']['features']['payment_method_update']['enabled'] === false);
    $api->configs['bpc_1']['is_default'] = true;
    $api->configs['bpc_1']['features']['subscription_update']['enabled'] = true;
    start($s);
    check(count($api->configs) === 2);
    check($api->configs['bpc_1']['features']['subscription_update']['enabled'] === true);
    check(end($api->sessions)['configuration'] === 'bpc_2');
});

if (function_exists('pcntl_fork')) {
    test('Concurrent paid webhooks across processes deliver exactly once', function() {
        global $api, $DB;
        $s = fresh(); [$id, $token] = start($s); $invoiceid = complete($s, $id, $token); $api->paid($invoiceid);
        $path = $DB->path; $children = [];
        for ($i = 0; $i < 4; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $DB = new moodle_database($path);
                (new invoice_service(new StripeClient(['api_key' => 'sk_test_fake'])))->process_event(event($invoiceid));
                exit(0);
            }
            check($pid > 0); $children[] = $pid;
        }
        foreach ($children as $pid) { pcntl_waitpid($pid, $status); check(pcntl_wexitstatus($status) === 0); }
        check($DB->count('payments') === 1 && $DB->count('enrolments') === 1);
    });
} else {
    echo "SKIP concurrency: pcntl extension not installed\n";
}
require_once(__DIR__ . '/email.php');
require_once(__DIR__ . '/migration.php');
require_once(__DIR__ . '/bank_transfer.php');

echo "OK: $passed scenarios, $assertions assertions\n";
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testroot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
rmdir($testroot);
