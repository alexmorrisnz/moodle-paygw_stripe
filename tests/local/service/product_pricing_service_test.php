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

use advanced_testcase;
use Stripe\Product;
use Stripe\Price;
use Stripe\StripeClient;

global $CFG;
require_once($CFG->dirroot . '/payment/gateway/stripe/.extlib/stripe-php/init.php');

/**
 * Tests for product_pricing_service.
 */
final class product_pricing_service_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests unit amount conversion for non-zero decimal currency.
     */
    public function test_get_unit_amount_for_non_zero_decimal_currency(): void {
        $service = new product_pricing_service(new product_pricing_test_fake_client());
        $this->assertSame(1050.0, $service->get_unit_amount(10.5, 'USD'));
    }

    /**
     * Tests unit amount conversion for zero-decimal currency.
     */
    public function test_get_unit_amount_for_zero_decimal_currency(): void {
        $service = new product_pricing_service(new product_pricing_test_fake_client());
        $this->assertSame(10.0, $service->get_unit_amount(10.0, 'JPY'));
    }

    /**
     * Tests get_product returns null when no record exists.
     */
    public function test_get_product_returns_null_when_no_db_record(): void {
        $service = new product_pricing_service(new product_pricing_test_fake_client());

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

        $stripeclient = new product_pricing_test_fake_client();
        $stripeclient->products->throwonretrieveids[] = 'prod_missing';
        $service = new product_pricing_service($stripeclient);

        $this->assertNull($service->get_product('enrol_fee', 'fee', '100'));
        $this->assertFalse($DB->record_exists('paygw_stripe_products', ['itemid' => 100]));
    }

    /**
     * Tests get_price returns null if Stripe prices API errors.
     */
    public function test_get_price_returns_null_on_api_error(): void {
        $stripeclient = new product_pricing_test_fake_client();
        $stripeclient->prices->throwonallproductids[] = 'prod_error';
        $service = new product_pricing_service($stripeclient);

        $product = Product::constructFrom(['id' => 'prod_error', 'name' => 'Error Product']);
        $this->assertNull($service->get_price($product, false));
    }

    /**
     * Tests create_price sends tax and recurring data to Stripe prices service.
     */
    public function test_create_price_includes_tax_and_recurring_payload(): void {
        $stripeclient = new product_pricing_test_fake_client();
        $service = new product_pricing_service($stripeclient);

        $service->create_price('usd', 'prod_1', 2500.0, true, 'exclusive', [
            'interval' => 'month',
            'interval_count' => 1,
        ]);

        $payload = $stripeclient->prices->lastcreatepayload;
        $this->assertSame('usd', $payload['currency']);
        $this->assertSame('prod_1', $payload['product']);
        $this->assertSame(2500.0, $payload['unit_amount']);
        $this->assertSame('exclusive', $payload['tax_behavior']);
        $this->assertSame('month', $payload['recurring']['interval']);
    }
}

/**
 * Minimal fake Stripe client for unit testing product_pricing_service without network calls.
 */
final class product_pricing_test_fake_client extends StripeClient {
    /** @var product_pricing_test_fake_products_service */
    public $products;
    /** @var product_pricing_test_fake_prices_service */
    public $prices;

    public function __construct() {
        $this->products = new product_pricing_test_fake_products_service();
        $this->prices = new product_pricing_test_fake_prices_service();
    }
}

/**
 * Fake products service.
 */
final class product_pricing_test_fake_products_service {
    /** @var array */
    private $products = [];
    /** @var int */
    private $counter = 0;
    /** @var array */
    public $throwonretrieveids = [];

    /**
     * @param array $data
     * @return Product
     */
    public function create(array $data): Product {
        $this->counter++;
        $id = 'prod_test_' . $this->counter;
        $product = Product::constructFrom(['id' => $id, 'name' => $data['name']]);
        $this->products[$id] = $product;
        return $product;
    }

    /**
     * @param string $id
     * @return Product
     */
    public function retrieve(string $id): Product {
        if (in_array($id, $this->throwonretrieveids, true)) {
            throw \Stripe\Exception\InvalidRequestException::factory('Missing product');
        }
        if (isset($this->products[$id])) {
            return $this->products[$id];
        }
        return Product::constructFrom(['id' => $id, 'name' => 'Recovered Product']);
    }
}

/**
 * Fake prices service.
 */
final class product_pricing_test_fake_prices_service {
    /** @var array */
    private $pricesbyproduct = [];
    /** @var int */
    private $counter = 0;
    /** @var array */
    public $throwonallproductids = [];
    /** @var array */
    public $lastcreatepayload = [];

    /**
     * @param array $params
     * @return array
     */
    public function all(array $params): array {
        $productid = $params['product'] ?? '';
        if (in_array($productid, $this->throwonallproductids, true)) {
            throw \Stripe\Exception\InvalidRequestException::factory('Prices list error');
        }
        return $this->pricesbyproduct[$productid] ?? [];
    }

    /**
     * @param array $data
     * @return Price
     */
    public function create(array $data): Price {
        $this->lastcreatepayload = $data;
        $this->counter++;
        $id = 'price_test_' . $this->counter;
        $price = Price::constructFrom([
            'id' => $id,
            'active' => true,
            'currency' => $data['currency'],
            'unit_amount' => $data['unit_amount'],
            'type' => isset($data['recurring']) ? 'recurring' : 'one_time',
            'recurring' => $data['recurring'] ?? null,
            'tax_behavior' => $data['tax_behavior'] ?? 'unspecified',
        ]);
        $this->pricesbyproduct[$data['product']][] = $price;
        return $price;
    }
}
