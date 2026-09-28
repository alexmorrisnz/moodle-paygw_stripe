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
}
