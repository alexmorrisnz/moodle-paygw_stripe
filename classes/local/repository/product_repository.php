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

namespace paygw_stripe\local\repository;

use paygw_stripe\local\model\product;

/**
 * Product table repository.
 *
 * @phpcs:ignore moodle.Commenting.ValidTags.Invalid
 * @extends   base_repository<product>
 * @package   paygw_stripe
 * @copyright Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class product_repository extends base_repository {
    /**
     * Returns the table name for the model.
     *
     * @return string
     */
    protected function table(): string {
        return 'paygw_stripe_products';
    }

    /**
     * Returns the model class name.
     *
     * @return class-string<product>
     */
    protected function model_class(): string {
        return product::class;
    }

    /**
     * Get product by component, paymentarea and itemid.
     *
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @return product|null
     * @throws \dml_exception
     */
    public function get_product_by_parts(string $component, string $paymentarea, string $itemid): product|null {
        global $DB;

        if (empty($component) || empty($paymentarea) || empty($itemid)) {
            return null;
        }

        $record = $DB->get_record(
            'paygw_stripe_products',
            ['component' => $component, 'paymentarea' => $paymentarea, 'itemid' => $itemid]
        );

        if (!$record) {
            return null;
        }

        return $this->hydrate($record);
    }

    /**
     * Delete product by component, paymentarea and itemid.
     *
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @return void
     * @throws \dml_exception
     */
    public function delete_product_by_parts(string $component, string $paymentarea, string $itemid): void {
        global $DB;

        $DB->delete_records('paygw_stripe_products', [
            'component' => $component,
            'paymentarea' => $paymentarea,
            'itemid' => $itemid,
        ]);
    }

    /**
     * Find a product by Stripe product id.
     *
     * @param string $productid
     * @return product|null
     * @throws \dml_exception
     */
    public function find_by_productid(string $productid): ?product {
        global $DB;
        $record = $DB->get_record('paygw_stripe_products', ['productid' => $productid]);
        if (!$record) {
            return null;
        }
        return $this->hydrate($record);
    }
}
