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
 * Tests for customer service logic.
 *
 * @package    paygw_stripe
 * @category   test
 * @copyright  2026 Alex Morris
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace paygw_stripe\local\service;

use advanced_testcase;
use Stripe\Customer;
use Stripe\StripeClient;

global $CFG;
require_once($CFG->dirroot . '/payment/gateway/stripe/.extlib/stripe-php/init.php');

/**
 * Tests for customer_service.
 */
final class customer_service_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests get_customer deletes stale DB record if Stripe retrieval fails.
     */
    public function test_get_customer_deletes_stale_record_on_api_error(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('paygw_stripe_customers', (object)[
            'userid' => $user->id,
            'customerid' => 'cus_missing',
        ]);

        $stripeclient = new customer_test_fake_client();
        $stripeclient->customers->throwonretrieveids[] = 'cus_missing';
        $service = new customer_service($stripeclient);

        $this->assertNull($service->get_customer((int)$user->id));
        $this->assertFalse($DB->record_exists('paygw_stripe_customers', ['userid' => $user->id]));
    }
}

/**
 * Minimal fake Stripe client for unit testing customer_service without network calls.
 */
final class customer_test_fake_client extends StripeClient {
    /** @var customer_test_fake_customers_service */
    public $customers;

    public function __construct() {
        $this->customers = new customer_test_fake_customers_service();
    }
}

/**
 * Fake customers service.
 */
final class customer_test_fake_customers_service {
    /** @var array */
    public $throwonretrieveids = [];
    /** @var array */
    private $customers = [];

    /**
     * @param string $id
     * @return Customer
     */
    public function retrieve(string $id): Customer {
        if (in_array($id, $this->throwonretrieveids, true)) {
            throw \Stripe\Exception\InvalidRequestException::factory('Missing customer', 404, null, null, null, 'resource_missing');
        }
        return $this->customers[$id] ?? Customer::constructFrom(['id' => $id]);
    }
}
