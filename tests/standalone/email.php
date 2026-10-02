<?php
// phpcs:ignoreFile -- Isolated CLI fixtures deliberately mirror external interfaces.
// GPL v3 or later. Loaded by run.php; never sends email or contacts Stripe.

use paygw_stripe\local\repository\invoice_repository;
use paygw_stripe\local\service\invoice_service;
use Stripe\StripeClient;

test('Finalization is followed by one email to the Stripe billing address, without enrolment', function() {
    global $api, $DB;
    $s = fresh(); [$id, $token] = start($s);
    check((new invoice_repository())->find_by_id($id)->emailstatus === 'pending');
    $invoiceid = complete($s, $id, $token);
    $record = (new invoice_repository())->find_by_id($id);
    check($record->emailstatus === 'sent' && $record->timeemailstarted !== null && $record->timeemailsent !== null);
    check($api->sent === [['invoiceid' => $invoiceid, 'email' => 'billing@example.test',
        'key' => 'moodle-invoice-' . $record->tokenhash . '-send']]);
    $writes = array_values(array_filter($api->requests, fn($r) => $r['method'] === 'post' && str_starts_with($r['path'], '/v1/invoices')));
    check(array_column($writes, 'path') === ['/v1/invoices', '/v1/invoices/in_1/finalize', '/v1/invoices/in_1', '/v1/invoices/in_1/send']);
    check(end($writes)['params'] === []);
    check(!$s->process_event(event($invoiceid, 'invoice.sent')));
    check($s->complete_billing($id, 42, $token) === 'https://invoice.stripe.com/' . $invoiceid);
    check(count($api->sent) === 1 && !$record->delivered && $DB->count('payments') === 0 && $DB->count('enrolments') === 0);
});

test('Send failure, lost send response and local checkpoint failure retry without duplicate emails', function() {
    global $api, $DB;
    foreach (['before', 'response', 'checkpoint'] as $failure) {
        $s = fresh(); [$id, $token] = start($s); save_details($id);
        if ($failure === 'before') { $api->failbefore = '/v1/invoices/in_1/send'; }
        if ($failure === 'response') { $api->lose = '/v1/invoices/in_1/send'; }
        if ($failure === 'checkpoint') { $DB->failmarkemailsent = true; }
        rejects(fn() => $s->complete_billing($id, 42, $token), $failure === 'checkpoint' ? 'database failure' : 'invoiceemailfailed');
        $record = (new invoice_repository())->find_by_id($id);
        check($record->status === 'open' && $record->emailstatus === 'sending');
        check($record->timeemailstarted !== null && $record->timeemailsent === null);
        check(count($api->sent) === ($failure === 'before' ? 0 : 1));
        check($DB->count('payments') === 0 && $DB->count('enrolments') === 0);
        $s->complete_billing($id, 42, $token);
        $retried = (new invoice_repository())->find_by_id($id);
        check($retried->emailstatus === 'sent' && $retried->timeemailstarted === $record->timeemailstarted);
        check($retried->invoiceid === $record->invoiceid && count($api->invoices) === 1 && count($api->sent) === 1);
        $sends = array_values(array_filter($api->requests, fn($r) => $r['path'] === '/v1/invoices/in_1/send'));
        check(count($sends) === 2 && $sends[0]['key'] !== '' && $sends[0]['key'] === $sends[1]['key']);
    }
});

test('Unfinalized and void invoices are never emailed', function() {
    global $api;
    $s = fresh(); [$id, $token] = start($s); save_details($id);
    $api->failbefore = '/v1/invoices/in_1/finalize';
    rejects(fn() => $s->complete_billing($id, 42, $token), 'failure before remote write');
    check(count($api->sent) === 0 && $api->invoices['in_1']['status'] === 'draft');
    check((new invoice_repository())->find_by_id($id)->timeemailstarted === null);
    $api->invoices['in_1']['status'] = 'void';
    rejects(fn() => $s->complete_billing($id, 42, $token), 'invoiceunavailable');
    check(count($api->sent) === 0);
});

test('Persisted sent status prevents resending even after Stripe forgets idempotency keys', function() {
    global $api;
    $s = fresh(); [$id, $token] = start($s); complete($s, $id, $token);
    $repo = new invoice_repository(); $record = $repo->find_by_id($id);
    $record->timeemailstarted = time() - 48 * HOURSECS; $repo->save($record);
    $api->keys = [];
    $s->complete_billing($id, 42, $token);
    check(count($api->sent) === 1);
    check(count(array_filter($api->requests, fn($r) => $r['path'] === '/v1/invoices/in_1/send')) === 1);
});

test('Uncertain old email attempts require review instead of sending a second email', function() {
    global $api;
    $s = fresh(); [$id, $token] = start($s); save_details($id); $api->lose = '/v1/invoices/in_1/send';
    rejects(fn() => $s->complete_billing($id, 42, $token), 'invoiceemailfailed');
    $repo = new invoice_repository(); $record = $repo->find_by_id($id);
    $record->timeemailstarted = time() - 24 * HOURSECS; $repo->save($record);
    $api->keys = [];
    rejects(fn() => $s->complete_billing($id, 42, $token), 'invoiceemailrecoveryrequired');
    check(count($api->sent) === 1 && $repo->find_by_id($id)->timeemailsent === null);
});

if (function_exists('pcntl_fork')) {
    test('Concurrent callbacks across processes submit exactly one invoice email', function() {
        global $api, $DB, $testdir;
        $s = fresh(); [$id, $token] = start($s); save_details($id);
        $api->failbefore = '/v1/invoices/in_1/send';
        rejects(fn() => $s->complete_billing($id, 42, $token), 'invoiceemailfailed');
        $path = $DB->path; $children = [];
        for ($i = 0; $i < 4; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $DB = new moodle_database($path);
                (new invoice_service(new StripeClient(['api_key' => 'sk_test_fake'])))->complete_billing($id, 42, $token);
                file_put_contents($testdir . '/sent-' . $i, count($api->sent));
                exit(0);
            }
            check($pid > 0); $children[] = $pid;
        }
        foreach ($children as $pid) { pcntl_waitpid($pid, $status); check(pcntl_wexitstatus($status) === 0); }
        $sent = 0;
        for ($i = 0; $i < 4; $i++) { $sent += (int)file_get_contents($testdir . '/sent-' . $i); }
        check($sent === 1 && (new invoice_repository())->find_by_id($id)->emailstatus === 'sent');
        check($DB->count('payments') === 0 && $DB->count('enrolments') === 0);
    });
}
