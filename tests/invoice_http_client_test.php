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

namespace paygw_stripe;

defined('MOODLE_INTERNAL') || die();

use paygw_stripe\tests\fixtures\invoice_testcase;
use Stripe\Invoice;
use Stripe\Exception\InvalidRequestException;
use Stripe\Price;
use Stripe\Product;

require_once(__DIR__ . '/fixtures/invoice_testcase.php');

/**
 * Tests the stateful invoice HTTP fixture through the real SDK.
 *
 * @package paygw_stripe
 * @category test
 * @covers \paygw_stripe\tests\fixtures\invoice_http_client
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class invoice_http_client_test extends invoice_testcase {
    public function test_product_and_price_state_is_shared_across_sdk_services(): void {
        $product = $this->client->products->create(['name' => 'Course']);
        $this->assertInstanceOf(Product::class, $product);
        $this->assertSame('Course', $this->client->products->retrieve($product->id)->name);
        $params = [
            'product' => $product->id, 'currency' => 'usd', 'unit_amount' => 2500.0,
            'recurring' => ['interval' => 'month', 'interval_count' => 1],
        ];
        $options = ['idempotency_key' => 'price-test'];
        $price = $this->client->prices->create($params, $options);
        $this->assertInstanceOf(Price::class, $price);
        $this->assertSame($price->id, $this->client->prices->create($params, $options)->id);
        $this->assertCount(1, $this->http->prices);
        $this->assertSame('recurring', $price->type);
        $this->assertSame($price->id, $this->client->prices->all(['product' => $product->id])->first()->id);
        $this->assertCount(0, $this->client->prices->all(['product' => 'prod_other'])->data);
        $this->client->prices->update($price->id, ['active' => false]);
        $this->assertFalse($this->client->prices->retrieve($price->id)->active);
    }

    public function test_invoice_creation_reuses_idempotency_key_after_response_loss(): void {
        $this->http->lose = '/v1/invoices';
        $params = ['customer' => 'cus_test', 'currency' => 'usd', 'auto_advance' => false];
        $options = ['idempotency_key' => 'invoice-test'];
        try {
            $this->client->invoices->create($params, $options);
            $this->fail('Expected response loss');
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            $this->assertSame('Simulated response loss after remote write', $e->getMessage());
        }
        $invoice = $this->client->invoices->create($params, $options);
        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertCount(1, $this->http->invoices);
        $this->assertFalse($this->client->invoices->retrieve($invoice->id)->auto_advance);
        $this->assertSame(stripe_helper::$apiversion, $this->http->requests[0]['version']);
    }

    public function test_missing_resource_raises_sdk_exception(): void {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('No such product');
        $this->client->products->retrieve('prod_missing');
    }

    public function test_unsupported_endpoint_fails_explicitly(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected Stripe call: get /v1/balance');
        $this->client->balance->retrieve();
    }
}
