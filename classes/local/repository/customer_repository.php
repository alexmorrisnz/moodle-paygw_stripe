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

use paygw_stripe\local\model\customer;

/**
 * Customer table repository.
 *
 * @phpcs:ignore moodle.Commenting.ValidTags.Invalid
 * @extends   base_repository<customer>
 * @package   paygw_stripe
 * @copyright Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class customer_repository extends base_repository {
    /**
     * Returns the table name for the model.
     *
     * @return string
     */
    protected function table(): string {
        return 'paygw_stripe_customers';
    }

    /**
     * Returns the model class name.
     *
     * @return class-string<customer>
     */
    protected function model_class(): string {
        return customer::class;
    }

    /**
     * Find a customer record by Moodle userid.
     *
     * @param int $userid
     * @return customer|null
     * @throws \dml_exception
     */
    public function find_by_userid(int $userid): ?customer {
        $record = $this->db->get_record($this->table(), ['userid' => $userid]);

        if (!$record) {
            return null;
        }

        return $this->hydrate($record);
    }

    /**
     * Delete a customer record by Moodle userid.
     *
     * @param int $userid
     * @return void
     * @throws \dml_exception
     */
    public function delete_by_userid(int $userid): void {
        $this->db->delete_records($this->table(), ['userid' => $userid]);
    }

    /**
     * Release profile protection only when no active billing or invoice-creation request uses it.
     *
     * @param int $userid Moodle user ID
     * @param string $customerid Stripe customer ID
     * @param int $now Current timestamp
     */
    public function release_billing_identity_if_unused(int $userid, string $customerid, int $now): void {
        $this->db->set_field_select(
            $this->table(),
            'billingmanaged',
            0,
            'userid = :userid AND customerid = :customerid AND NOT EXISTS (' .
                'SELECT 1 FROM {paygw_stripe_invoices} invoices ' .
                'WHERE invoices.userid = :invoiceuserid AND invoices.customerid = :invoicecustomerid ' .
                'AND (invoices.status = :creating OR (invoices.status = :billing AND invoices.timeexpires >= :now)))',
            [
                'userid' => $userid,
                'customerid' => $customerid,
                'invoiceuserid' => $userid,
                'invoicecustomerid' => $customerid,
                'creating' => 'creating',
                'billing' => 'billing',
                'now' => $now,
            ]
        );
    }

    /**
     * Ensure profile protection remains enabled for a saved billing purchase.
     *
     * @param int $userid Moodle user ID
     * @param string $customerid Stripe customer ID
     */
    public function reserve_billing_identity(int $userid, string $customerid): void {
        $this->db->set_field($this->table(), 'billingmanaged', 1, [
            'userid' => $userid,
            'customerid' => $customerid,
        ]);
    }
}
