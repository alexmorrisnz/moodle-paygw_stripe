<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Tests for product pricing service logic.
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
use Stripe\Product;
use Stripe\Price;
use Stripe\Exception\InvalidRequestException;
use Stripe\Service\ProductService;
use Stripe\Service\PriceService;

require_once(__DIR__ . '/../../fixtures/stripe_testcase.php');

/**
 * Tests for product_pricing_service.
 *
 * @covers \paygw_stripe\local\service\product_pricing_service
 */
final class product_pricing_service_test extends stripe_testcase {
    /**
     * Tests unit amount conversion for non-zero decimal currency.
     */
    public function test_get_unit_amount_for_non_zero_decimal_currency(): void {
        $service = new product_pricing_service($this->client);
        $this->assertSame(1050.0, $service->get_unit_amount(10.5, 'USD'));
    }

    /**
     * Tests unit amount conversion for zero-decimal currency.
     */
    public function test_get_unit_amount_for_zero_decimal_currency(): void {
        $service = new product_pricing_service($this->client);
        $this->assertSame(10.0, $service->get_unit_amount(10.0, 'JPY'));
    }

    /**
     * Tests get_product returns null when no record exists.
     */
    public function test_get_product_returns_null_when_no_db_record(): void {
        $service = new product_pricing_service($this->client);

        $this->assertNull($service->get_product('enrol_fee', 'fee', '100'));
    }

    /**
     * Tests get_product deletes stale DB record if Stripe retrieval fails.
     */
    public function test_get_product_deletes_stale_record_on_api_error(): void {
        global $DB;

        $DB->insert_record('paygw_stripe_products', (object)[
            'component' => 'enrol_fee',
            'paymentarea' => 'fee',
            'itemid' => 100,
            'productid' => 'prod_missing',
        ]);

        $products = $this->mock_stripe_service('products', ProductService::class);
        $products->expects($this->once())->method('retrieve')->with('prod_missing')
            ->willThrowException(InvalidRequestException::factory('Missing product'));
        $service = new product_pricing_service($this->client);

        $this->assertNull($service->get_product('enrol_fee', 'fee', '100'));
        $this->assertFalse($DB->record_exists('paygw_stripe_products', ['itemid' => 100]));
    }

    /**
     * Tests get_price returns null if Stripe prices API errors.
     */
    public function test_get_price_returns_null_on_api_error(): void {
        $prices = $this->mock_stripe_service('prices', PriceService::class);
        $prices->expects($this->once())->method('all')->with(['product' => 'prod_error'])
            ->willThrowException(InvalidRequestException::factory('Prices list error'));
        $service = new product_pricing_service($this->client);

        $product = Product::constructFrom(['id' => 'prod_error', 'name' => 'Error Product']);
        $this->assertNull($service->get_price($product, false));
    }

    /**
     * Tests create_price sends tax and recurring data to Stripe prices service.
     */
    public function test_create_price_includes_tax_and_recurring_payload(): void {
        $service = new product_pricing_service($this->client);

        $prices = $this->mock_stripe_service('prices', PriceService::class);
        $prices->expects($this->once())->method('create')->with([
            'currency' => 'usd',
            'product' => 'prod_1',
            'unit_amount' => 2500.0,
            'tax_behavior' => 'exclusive',
            'recurring' => ['interval' => 'month', 'interval_count' => 1],
        ])->willReturn(Price::constructFrom(['id' => 'price_test']));
        $service->create_price('usd', 'prod_1', 2500.0, true, 'exclusive', [
            'interval' => 'month',
            'interval_count' => 1,
        ]);
    }
}
