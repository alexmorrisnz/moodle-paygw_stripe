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

use advanced_testcase;
use paygw_stripe\local\repository\invoice_repository;
use paygw_stripe\stripe_helper;
use paygw_stripe\tests\fixtures\invoice_http_client;
use Stripe\ApiRequestor;
use Stripe\StripeClient;

global $CFG;
require_once($CFG->dirroot . '/payment/gateway/stripe/.extlib/stripe-php/init.php');
require_once(__DIR__ . '/fixtures/invoice_http_client.php');

/**
 * Exercises guarded Moodle XMLDB upgrade paths without altering the PHPUnit schema.
 *
 * @package paygw_stripe
 * @category test
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class invoice_upgrade_test extends advanced_testcase {
    private $originalhttpclient;
    private invoice_http_client $http;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $property = new \ReflectionProperty(ApiRequestor::class, '_httpClient');
        $property->setAccessible(true);
        $this->originalhttpclient = $property->getValue();
        $this->http = new invoice_http_client();
        ApiRequestor::setHttpClient($this->http);
    }

    protected function tearDown(): void {
        ApiRequestor::setHttpClient($this->originalhttpclient);
        parent::tearDown();
    }

    public function test_invoice_upgrade_preserves_legacy_defaults_and_does_not_email_old_invoices(): void {
        global $CFG, $DB;

        $user = $this->getDataGenerator()->create_user();
        // No Stripe gateway: the API-version savepoint has no endpoint to recreate.
        $account = $this->getDataGenerator()->get_plugin_generator('core_payment')->create_payment_account();
        $accountid = (int)$account->get('id');
        $userid = (int)$user->id;
        $token = bin2hex(random_bytes(32));
        $tokenhash = hash('sha256', $token);
        $customerid = 'cus_legacy';
        $invoiceid = 'in_legacy';
        $now = time();
        // Insert only fields present before the email and bank-transfer upgrades. XMLDB defaults
        // represent how the newly added fields are populated for existing database rows.
        $id = $DB->insert_record('paygw_stripe_invoices', (object)[
            'userid' => $userid,
            'paymentaccountid' => $accountid,
            'customerid' => $customerid,
            'component' => 'enrol_fee',
            'paymentarea' => 'fee',
            'itemid' => 42,
            'amount' => 4995,
            'currency' => 'eur',
            'description' => 'Legacy fee',
            'automatictax' => 0,
            'taxbehavior' => 'inclusive',
            'tokenhash' => $tokenhash,
            'timecreated' => $now,
            'timeexpires' => $now + HOURSECS,
            'status' => 'open',
            'delivered' => 0,
            'timemodified' => $now,
            'invoiceid' => $invoiceid,
            'invoiceitemid' => 'ii_legacy',
            'portalsessionid' => 'bps_legacy',
            'priceid' => 'price_legacy',
            'amounttotal' => 4995,
            'timeconfirmed' => $now,
        ]);
        $before = $DB->get_record('paygw_stripe_invoices', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('legacy', $before->emailstatus);
        $this->assertSame('legacy', $before->paymentmethodstatus);
        $this->assertNull($before->banktransfercountry);
        $this->assertNull($before->paymentmethods);
        $this->assertNull($before->timeemailstarted);
        $this->assertNull($before->timeemailsent);

        $table = new \xmldb_table('paygw_stripe_invoices');
        $manager = $DB->get_manager();
        foreach (
            ['emailstatus', 'timeemailstarted', 'timeemailsent', 'banktransfercountry',
            'paymentmethodstatus', 'paymentmethods'] as $field
        ) {
            $this->assertTrue($manager->field_exists($table, new \xmldb_field($field)));
        }

        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/payment/gateway/stripe/db/upgrade.php');
        set_config('version', 2026092400, 'paygw_stripe');
        $this->assertTrue(xmldb_paygw_stripe_upgrade(2026092400));
        $this->assertSame(2026100400, (int)get_config('paygw_stripe', 'version'));
        $this->assertSame([], $this->http->requests);
        $after = $DB->get_record('paygw_stripe_invoices', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals($before, $after);

        $this->http->invoices[$invoiceid] = [
            'id' => $invoiceid,
            'object' => 'invoice',
            'customer' => $customerid,
            'currency' => 'eur',
            'collection_method' => 'send_invoice',
            'status' => 'open',
            'total' => 4995,
            'amount_remaining' => 4995,
            'hosted_invoice_url' => 'https://invoice.stripe.com/' . $invoiceid,
            'metadata' => [
                'gateway' => 'paygw_stripe',
                'flow' => 'invoice',
                'moodle_site' => hash('sha256', $CFG->wwwroot),
                'transactionid' => (string)$id,
                'paymentaccountid' => (string)$accountid,
                'userid' => (string)$userid,
                'component' => 'enrol_fee',
                'paymentarea' => 'fee',
                'itemid' => '42',
            ],
        ];
        $service = new invoice_service(new StripeClient([
            'api_key' => 'sk_test_fake',
            'stripe_version' => stripe_helper::$apiversion,
        ]));
        $this->assertSame(
            'https://invoice.stripe.com/' . $invoiceid,
            $service->complete_billing($id, $userid, $token)
        );
        $this->assertSame([], $this->http->sent);
        $this->assertSame('legacy', (new invoice_repository())->find_by_id($id)->emailstatus);
        $this->assertSame('legacy', (new invoice_repository())->find_by_id($id)->paymentmethodstatus);
        $this->assertCount(1, $this->http->requests);
        $this->assertSame('/v1/invoices/' . $invoiceid, $this->http->requests[0]['path']);
    }
}
