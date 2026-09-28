<?php
// phpcs:ignoreFile -- Standalone regression tests use the bundled SDK and fake HTTP transport.
// GPL v3 or later. Loaded by run.php; no live Stripe requests.

use paygw_stripe\local\model\invoice;
use paygw_stripe\local\repository\invoice_repository;
use paygw_stripe\local\service\invoice_service;

test('New EUR invoices preserve Stripe defaults and add EU bank transfer before email', function() {
    global $api, $DB;
    $s = fresh(); [$id, $token] = start($s);
    check((new invoice_repository())->find_by_id($id)->banktransfercountry === 'DE');
    $invoiceid = complete($s, $id, $token);
    $writes = array_values(array_filter($api->requests, fn($r) => $r['method'] === 'post' && $r['path'] === '/v1/invoices'));
    check(count($writes) === 1);
    check(!array_key_exists('payment_settings', $writes[0]['params']));
    $updates = array_values(array_filter($api->requests, fn($r) => $r['method'] === 'post' && $r['path'] === '/v1/invoices/' . $invoiceid));
    check(count($updates) === 1);
    $settings = $updates[0]['params']['payment_settings'];
    check($settings['payment_method_types'] === ['card', 'paypal', 'sepa_debit', 'customer_balance']);
    $balance = $settings['payment_method_options']['customer_balance'];
    check($balance['funding_type'] === 'bank_transfer');
    check($balance['bank_transfer']['type'] === 'eu_bank_transfer');
    check($balance['bank_transfer']['eu_bank_transfer']['country'] === 'DE');
    check($api->invoices[$invoiceid]['payment_settings'] === $settings);
    check(count($api->sent) === 1 && $DB->count('payments') === 0 && $DB->count('enrolments') === 0);
    $api->paid($invoiceid);
    check($s->process_event(event($invoiceid)) && $s->process_event(event($invoiceid)));
    check($DB->count('payments') === 1 && $DB->count('enrolments') === 1);
});

test('Configured EUR bank country is independent of customer billing country', function() {
    global $api;
    foreach (['DE', 'FR', 'IE', 'NL', ' fr '] as $country) {
        $s = fresh(); [$id, $token] = start($s, 49.95, 'eur', false, $country);
        $invoiceid = complete($s, $id, $token);
        $record = (new invoice_repository())->find_by_id($id);
        check($record->banktransfercountry === strtoupper(trim($country)));
        check($api->customers[$record->customerid]['address']['country'] === 'DE');
        check($api->invoices[$invoiceid]['payment_settings']['payment_method_options']['customer_balance']
            ['bank_transfer']['eu_bank_transfer']['country'] === strtoupper(trim($country)));
    }
});

test('Invalid EUR bank countries are rejected before any Stripe or purchase writes', function() {
    global $api, $DB;
    foreach (['', 'AT', 'US', 'invalid'] as $country) {
        $s = fresh();
        rejects(fn() => start($s, 49.95, 'EUR', false, $country), 'invalidinvoicebankcountry');
        check(count($api->requests) === 0 && $DB->count('paygw_stripe_invoices') === 0);
    }
});

test('Non-EUR invoices retain Stripe payment defaults without EUR bank options', function() {
    global $api;
    foreach (['USD', 'GBP', 'JPY'] as $currency) {
        $s = fresh(); [$id, $token] = start($s, 50, $currency, false, 'AT');
        $invoiceid = complete($s, $id, $token);
        check((new invoice_repository())->find_by_id($id)->banktransfercountry === null);
        $request = array_values(array_filter($api->requests, fn($r) => $r['method'] === 'post' && $r['path'] === '/v1/invoices'))[0];
        check(!array_key_exists('payment_settings', $request['params']));
        check($api->invoices[$invoiceid]['currency'] === strtolower($currency) && count($api->sent) === 1);
    }
});

test('Lost invoice creation response reuses the saved country while later purchases use a new setting', function() {
    global $api, $client;
    $s = fresh(); [$id, $token] = start($s, 49.95, 'EUR', false, 'FR'); save_details($id);
    $api->lose = '/v1/invoices';
    rejects(fn() => $s->complete_billing($id, 42, $token), 'Simulated response loss');
    [$other, $othertoken] = start($s, 49.95, 'EUR', false, 'NL');
    $s = new invoice_service($client);
    $invoiceid = complete($s, $id, $token);
    check($invoiceid === 'in_1' && count($api->invoices) === 1);
    $record = (new invoice_repository())->find_by_id($id);
    check($record->banktransfercountry === 'FR');
    $requests = array_values(array_filter($api->requests, fn($r) => $r['key'] === 'moodle-invoice-' . $record->tokenhash . '-create'));
    check(count($requests) === 2 && $requests[0]['params'] === $requests[1]['params']);
    $otherinvoice = complete($s, $other, $othertoken);
    check($api->invoices[$otherinvoice]['payment_settings']['payment_method_options']['customer_balance']
        ['bank_transfer']['eu_bank_transfer']['country'] === 'NL');
    $s->complete_billing($id, 42, $token);
    check(count($api->invoices) === 2 && count($api->sent) === 2);
});

test('Upgrade from email release preserves old EUR requests and uncertain idempotent writes', function() {
    global $api, $DB, $CFG, $testdir;
    $s = fresh(); $repo = new invoice_repository(); $purchases = [];
    foreach (['billing', 'creating', 'open', 'paid'] as $status) {
        [$id, $token] = start($s);
        // Simulate purchase snapshots created by the previous plugin release.
        $record = $repo->find_by_id($id); $record->banktransfercountry = null; $record->paymentmethodstatus = 'legacy'; $repo->save($record);
        if ($status === 'creating') {
            save_details($id); $api->lose = '/v1/invoices';
            rejects(fn() => $s->complete_billing($id, 42, $token), 'Simulated response loss');
        } else if ($status !== 'billing') {
            $invoiceid = complete($s, $id, $token);
            if ($status === 'paid') { $api->paid($invoiceid); $s->process_event(event($invoiceid)); }
        }
        $purchases[] = [$id, $token, $status, $repo->find_by_id($id)->emailstatus];
    }
    $schema = fn() => $DB->pdo->query('PRAGMA table_info(paygw_stripe_invoices)')->fetchAll(PDO::FETCH_ASSOC);
    $freshschema = $schema();
    foreach (['paymentmethods', 'paymentmethodstatus', 'banktransfercountry'] as $field) {
        $DB->pdo->exec('ALTER TABLE paygw_stripe_invoices DROP COLUMN ' . $field);
    }
    check(invoice::from_record($DB->get_record('paygw_stripe_invoices', ['id' => $id]))->banktransfercountry === null);
    $before = count($api->requests);
    $CFG->dirroot = $testdir . '/moodle';
    mkdir($CFG->dirroot . '/payment/gateway', 0700, true);
    symlink(dirname(__DIR__, 2), $CFG->dirroot . '/payment/gateway/stripe');
    try {
        $GLOBALS['savepoints'] = [];
        check(xmldb_paygw_stripe_upgrade(2026092500));
        check($GLOBALS['savepoints'] === [[true, 2026092501, 'paygw', 'stripe'], [true, 2026092502, 'paygw', 'stripe']]);
        check($schema() === $freshschema && count($api->requests) === $before);
        check(xmldb_paygw_stripe_upgrade(2026092500) && $schema() === $freshschema);
    } finally {
        unlink($CFG->dirroot . '/payment/gateway/stripe');
        unset($CFG->dirroot);
    }
    foreach ($purchases as [$id, $token, $status, $emailstatus]) {
        $record = $repo->find_by_id($id);
        check($record->status === $status && $record->emailstatus === $emailstatus && $record->banktransfercountry === null);
        $invoiceid = complete($s, $id, $token);
        check(!array_key_exists('payment_settings', $api->invoices[$invoiceid]));
    }
    check(count($api->invoices) === 4 && count($api->sent) === 4);
    [$id, $token] = start($s);
    $invoiceid = complete($s, $id, $token);
    check($repo->find_by_id($id)->banktransfercountry === 'DE');
    check($api->invoices[$invoiceid]['payment_settings']['payment_method_types'] === ['card', 'paypal', 'sepa_debit', 'customer_balance']);
});

test('Stripe invoice defaults are used in full without a hard-coded card fallback', function() {
    global $api;
    foreach ([['paypal'], ['card', 'link', 'klarna', 'ideal'], ['sepa_debit', 'customer_balance']] as $defaults) {
        $s = fresh(); $api->invoicemethods = $defaults;
        [$id, $token] = start($s); $invoiceid = complete($s, $id, $token);
        $expected = array_values(array_unique([...$defaults, 'customer_balance']));
        check($api->invoices[$invoiceid]['payment_settings']['payment_method_types'] === $expected);
        $record = (new invoice_repository())->find_by_id($id);
        check($record->paymentmethodstatus === 'ready' && json_decode($record->paymentmethods, true) === $expected);
        $requests = count($api->requests);
        $s->complete_billing($id, 42, $token);
        check(count($api->requests) === $requests + 1, 'Ready invoices must only be retrieved, not reconfigured');
    }
});

test('Failed payment-method updates resume the same snapshot before email, including lost responses', function() {
    global $api, $DB, $client;
    foreach (['before', 'response', 'checkpoint'] as $failure) {
        $s = fresh(); [$id, $token] = start($s); save_details($id);
        if ($failure === 'before') { $api->failbefore = '/v1/invoices/in_1'; }
        if ($failure === 'response') { $api->lose = '/v1/invoices/in_1'; }
        if ($failure === 'checkpoint') { $DB->failmarkmethodsready = true; }
        rejects(fn() => $s->complete_billing($id, 42, $token),
            $failure === 'checkpoint' ? 'database failure' : 'invoicepaymentmethodsfailed');
        $repo = new invoice_repository(); $record = $repo->find_by_id($id);
        check($record->paymentmethodstatus === 'applying' && $record->emailstatus === 'pending');
        check(count($api->sent) === 0 && $DB->count('payments') === 0);
        $api->invoicemethods = ['card', 'klarna'];
        (new invoice_service($client))->complete_billing($id, 42, $token);
        check(count($api->invoices) === 1 && count($api->sent) === 1);
        check($repo->find_by_id($id)->paymentmethods === $record->paymentmethods);
        $updates = array_values(array_filter($api->requests, fn($r) => $r['method'] === 'post' && $r['path'] === '/v1/invoices/in_1'));
        check(count($updates) === 2 && $updates[0]['params'] === $updates[1]['params'] && $updates[0]['key'] === $updates[1]['key']);
        check($api->invoices['in_1']['payment_settings']['payment_method_types'] === ['card', 'paypal', 'sepa_debit', 'customer_balance']);
    }
});

test('Unreadable or missing Stripe defaults do not silently restrict the invoice to bank transfer', function() {
    global $api;
    $s = fresh(); [$id, $token] = start($s); save_details($id);
    $api->failbefore = '/v1/invoice_payments';
    rejects(fn() => $s->complete_billing($id, 42, $token), 'invoicepaymentmethodsfailed');
    check(count($api->sent) === 0 && (new invoice_repository())->find_by_id($id)->paymentmethodstatus === 'pending');
    $payment = $api->invoicepayments['in_1'];
    $api->invoicepayments = [];
    rejects(fn() => $s->complete_billing($id, 42, $token), 'invoicepaymentmethodsfailed');
    check(count($api->sent) === 0);
    $api->invoicepayments['in_1'] = $payment;
    $api->expandintents = false;
    $s->complete_billing($id, 42, $token);
    check(count($api->sent) === 1);
    check(count(array_filter($api->requests, fn($r) => $r['path'] === '/v1/payment_intents/pi_in_1')) === 1);
});

test('Unrelated invoice payments are ignored and existing card options survive the update', function() {
    global $api;
    $s = fresh(); [$id, $token] = start($s); save_details($id);
    $api->failbefore = '/v1/invoice_payments';
    rejects(fn() => $s->complete_billing($id, 42, $token), 'invoicepaymentmethodsfailed');
    $api->paymentintents['pi_unrelated'] = ['id' => 'pi_unrelated', 'object' => 'payment_intent',
        'customer' => 'cus_other', 'currency' => 'eur', 'payment_method_types' => ['card']];
    $api->invoicepayments = ['unrelated' => ['id' => 'inpay_other', 'object' => 'invoice_payment', 'invoice' => 'in_1',
        'is_default' => false, 'status' => 'open', 'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_unrelated']]]
        + $api->invoicepayments;
    $api->invoices['in_1']['payment_settings']['payment_method_options']['card'] = ['request_three_d_secure' => 'any'];
    $s->complete_billing($id, 42, $token);
    check($api->invoices['in_1']['payment_settings']['payment_method_types'] === ['card', 'paypal', 'sepa_debit', 'customer_balance']);
    check($api->invoices['in_1']['payment_settings']['payment_method_options']['card']['request_three_d_secure'] === 'any');
});

test('Fully credited EUR invoices are sent without looking up or configuring a payment intent', function() {
    global $api;
    $s = fresh(); $api->autopaid = true;
    [$id, $token] = start($s); complete($s, $id, $token);
    check(count($api->sent) === 1 && (new invoice_repository())->find_by_id($id)->paymentmethodstatus === 'ready');
    check(count(array_filter($api->requests, fn($r) => $r['path'] === '/v1/invoice_payments' ||
        ($r['method'] === 'post' && $r['path'] === '/v1/invoices/in_1'))) === 0);
});

test('Upgrade from bank-transfer-only release preserves uncertain creation parameters', function() {
    global $api, $DB, $CFG, $testdir;
    $s = fresh(); $repo = new invoice_repository();
    [$id, $token] = start($s); save_details($id);
    $record = $repo->find_by_id($id); $record->paymentmethodstatus = 'legacy'; $repo->save($record);
    $api->lose = '/v1/invoices';
    rejects(fn() => $s->complete_billing($id, 42, $token), 'Simulated response loss');
    $schema = fn() => $DB->pdo->query('PRAGMA table_info(paygw_stripe_invoices)')->fetchAll(PDO::FETCH_ASSOC);
    $freshschema = $schema();
    foreach (['paymentmethods', 'paymentmethodstatus'] as $field) {
        $DB->pdo->exec('ALTER TABLE paygw_stripe_invoices DROP COLUMN ' . $field);
    }
    check($repo->find_by_id($id)->paymentmethodstatus === 'legacy');
    $before = count($api->requests);
    $CFG->dirroot = $testdir . '/moodle';
    mkdir($CFG->dirroot . '/payment/gateway', 0700, true);
    symlink(dirname(__DIR__, 2), $CFG->dirroot . '/payment/gateway/stripe');
    try {
        $GLOBALS['savepoints'] = [];
        check(xmldb_paygw_stripe_upgrade(2026092501));
        check($GLOBALS['savepoints'] === [[true, 2026092502, 'paygw', 'stripe']]);
        check($schema() === $freshschema && count($api->requests) === $before);
        check(xmldb_paygw_stripe_upgrade(2026092501) && $schema() === $freshschema);
    } finally {
        unlink($CFG->dirroot . '/payment/gateway/stripe');
        unset($CFG->dirroot);
    }
    $s->complete_billing($id, 42, $token);
    check(count($api->invoices) === 1 && count($api->sent) === 1);
    check($api->invoices['in_1']['payment_settings']['payment_method_types'] === ['customer_balance']);
    [$newid, $newtoken] = start($s); $invoiceid = complete($s, $newid, $newtoken);
    check($api->invoices[$invoiceid]['payment_settings']['payment_method_types'] === ['card', 'paypal', 'sepa_debit', 'customer_balance']);
});
