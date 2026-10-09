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

defined('MOODLE_INTERNAL') || die();

use paygw_stripe\tests\fixtures\stripe_testcase;
use Stripe\Exception\InvalidRequestException;
use Stripe\Service\CustomerService;

require_once(__DIR__ . '/../../fixtures/stripe_testcase.php');

/**
 * Tests for customer_service.
 *
 * @covers \paygw_stripe\local\service\customer_service
 */
final class customer_service_test extends stripe_testcase {
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

        $customers = $this->mock_stripe_service('customers', CustomerService::class);
        $customers->expects($this->once())->method('retrieve')->with('cus_missing')->willThrowException(
            InvalidRequestException::factory('Missing customer', 404, null, null, null, 'resource_missing')
        );
        $service = new customer_service($this->client);

        $this->assertNull($service->get_customer((int)$user->id));
        $this->assertFalse($DB->record_exists('paygw_stripe_customers', ['userid' => $user->id]));
    }
}
