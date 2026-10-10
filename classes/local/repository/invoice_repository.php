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

use paygw_stripe\local\model\invoice;

/**
 * Persistent purchase/invoice relationships, including abandoned billing steps.
 * @package paygw_stripe
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class invoice_repository extends base_repository {
    /**
     * Return the invoice purchase table.
     * @return string
     */
    protected function table(): string {
        return 'paygw_stripe_invoices';
    }

    /**
     * Return the invoice model class.
     * @return string
     */
    protected function model_class(): string {
        return invoice::class;
    }

    /**
     * Find only a locally registered Stripe invoice; metadata alone is insufficient.
     * @param string $invoiceid
     * @return invoice|null
     */
    public function find_by_invoiceid(string $invoiceid): ?invoice {
        $record = $this->db->get_record($this->table(), ['invoiceid' => $invoiceid]);
        return $record ? $this->hydrate($record) : null;
    }

    /**
     * Find an active request or undelivered invoice for the same purchase and amount.
     * @param int $userid
     * @param int $paymentaccountid
     * @param string $component
     * @param string $paymentarea
     * @param int $itemid
     * @param int $amount
     * @param string $currency
     * @param int $now
     * @return invoice|null
     */
    public function find_active_purchase(
        int $userid,
        int $paymentaccountid,
        string $component,
        string $paymentarea,
        int $itemid,
        int $amount,
        string $currency,
        int $now
    ): ?invoice {
        $select = 'userid = :userid AND paymentaccountid = :paymentaccountid AND component = :component ' .
            'AND paymentarea = :paymentarea AND itemid = :itemid AND amount = :amount AND currency = :currency ' .
            'AND (status = :open OR status = :creating OR (status = :paid AND delivered = 0) ' .
            'OR (status = :billing AND timeexpires >= :now))';
        $params = [
            'userid' => $userid,
            'paymentaccountid' => $paymentaccountid,
            'component' => $component,
            'paymentarea' => $paymentarea,
            'itemid' => $itemid,
            'amount' => $amount,
            'currency' => $currency,
            'open' => 'open',
            'creating' => 'creating',
            'paid' => 'paid',
            'billing' => 'billing',
            'now' => $now,
        ];
        $records = $this->db->get_records_select($this->table(), $select, $params, 'id DESC', '*', 0, 1);
        if (!$records) {
            return null;
        }
        $record = reset($records);
        return $record ? $this->hydrate($record) : null;
    }
}
