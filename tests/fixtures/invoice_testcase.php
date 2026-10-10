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

namespace paygw_stripe\tests\fixtures;

defined('MOODLE_INTERNAL') || die();

use paygw_stripe\stripe_helper;
use Stripe\ApiRequestor;
use Stripe\StripeClient;

global $CFG;
require_once($CFG->dirroot . '/payment/gateway/stripe/.extlib/stripe-php/init.php');
require_once(__DIR__ . '/invoice_http_client.php');

/**
 * Exercises stateful invoice flows through the real SDK without network access.
 *
 * @package paygw_stripe
 * @category test
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class invoice_testcase extends \advanced_testcase {
    /** @var invoice_http_client In-memory invoice API. */
    protected invoice_http_client $http;
    /** @var StripeClient Real SDK client. */
    protected StripeClient $client;
    /** @var \Stripe\HttpClient\ClientInterface|null Original HTTP client. */
    private $originalhttpclient;

    /**
     * Install a fresh invoice API fixture.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $property = new \ReflectionProperty(ApiRequestor::class, '_httpClient');
        $property->setAccessible(true);
        $this->originalhttpclient = $property->getValue();
        $this->http = new invoice_http_client();
        ApiRequestor::setHttpClient($this->http);
        $this->client = new StripeClient([
            'api_key' => 'sk_test_fake',
            'stripe_version' => stripe_helper::$apiversion,
        ]);
    }

    /**
     * Restore the original HTTP client.
     */
    protected function tearDown(): void {
        ApiRequestor::setHttpClient($this->originalhttpclient);
        parent::tearDown();
    }
}
