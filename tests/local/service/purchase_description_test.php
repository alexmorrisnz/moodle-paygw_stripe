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

namespace paygw_stripe\local\service;

defined('MOODLE_INTERNAL') || die();

use core_payment\local\entities\payable;
use paygw_stripe\tests\fixtures\stripe_testcase;
use PHPUnit\Framework\Attributes\CoversClass;
use Stripe\Collection;
use Stripe\Price;
use Stripe\Product;
use Stripe\Service\PriceService;
use Stripe\Service\ProductService;

require_once(__DIR__ . '/../../fixtures/stripe_testcase.php');

/**
 * Trusted Stripe purchase labels.
 *
 * @package paygw_stripe
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(purchase_description::class)]
#[CoversClass(product_pricing_service::class)]
final class purchase_description_test extends stripe_testcase {
    /**
     * Fee labels use the stored course name rather than caller text.
     */
    public function test_fee_description_uses_course_name(): void {
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Trusted & safe course']);
        $id = enrol_get_plugin('fee')->add_instance($course);
        $this->assertSame(
            get_string('purchasedescription', 'enrol_fee', 'Trusted & safe course'),
            purchase_description::get('enrol_fee', 'fee', $id)
        );
    }

    /**
     * Other components receive a purchase reference without any browser input.
     */
    public function test_generic_description_uses_purchase_reference(): void {
        $this->assertSame('Purchase mod_example / access / 42', purchase_description::get('mod_example', 'access', 42));
    }

    /**
     * Invalid fee targets do not silently fall back to caller text.
     */
    public function test_missing_fee_instance_is_rejected(): void {
        $this->expectException(\dml_missing_record_exception::class);
        purchase_description::get('enrol_fee', 'fee', 999999);
    }

    /**
     * Even direct product creation ignores an untrusted label.
     */
    public function test_product_creation_ignores_supplied_description(): void {
        $products = $this->mock_stripe_service('products', ProductService::class);
        $products->expects($this->once())->method('create')
            ->with(['name' => 'Purchase mod_example / access / 42'])
            ->willReturn(Product::constructFrom(['id' => 'prod_trusted']));
        (new product_pricing_service($this->client))->create_product(
            'Send payment elsewhere', 'mod_example', 'access', '42'
        );
    }

    /**
     * An already poisoned shared product is repaired, never renamed to caller text.
     */
    public function test_shared_product_update_uses_trusted_description(): void {
        global $DB;

        $DB->insert_record('paygw_stripe_products', (object)[
            'component' => 'mod_example', 'paymentarea' => 'access', 'itemid' => 42, 'productid' => 'prod_shared',
        ]);
        $products = $this->mock_stripe_service('products', ProductService::class);
        $products->method('retrieve')->willReturn(Product::constructFrom([
            'id' => 'prod_shared', 'name' => 'Send payment elsewhere',
        ]));
        $products->expects($this->once())->method('update')
            ->with('prod_shared', ['name' => 'Purchase mod_example / access / 42'])
            ->willReturn(Product::constructFrom(['id' => 'prod_shared', 'name' => 'Purchase mod_example / access / 42']));
        $prices = $this->mock_stripe_service('prices', PriceService::class);
        $prices->method('all')->willReturn(Collection::constructFrom([
            'object' => 'list', 'data' => [], 'has_more' => false,
        ]));
        $prices->method('create')->willReturn(Price::constructFrom(['id' => 'price_trusted']));
        [$product] = (new product_pricing_service($this->client))->create_product_and_price(
            (object)['enableautomatictax' => 0, 'defaulttaxbehavior' => 'inclusive'],
            new payable(10, 'USD', 1),
            'Different attacker text',
            10,
            'mod_example',
            'access',
            '42'
        );
        $this->assertSame('Purchase mod_example / access / 42', $product->name);
    }
}
