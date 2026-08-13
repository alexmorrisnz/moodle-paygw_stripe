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

namespace paygw_stripe\local\model;

/**
 * Webhook model.
 *
 * @copyright 2026 Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class webhook implements mappable_model {
    use mapping_helper;

    public function __construct(
        public readonly ?int $id,
        public readonly int $paymentaccountid,
        public readonly string $webhookid,
        public readonly string $secret
    ) {
    }

    public static function from_record(\stdClass $record): self {
        return new self(
            id: self::nullable_int_field($record, 'id'),
            paymentaccountid: (int)$record->paymentaccountid,
            webhookid: (string)$record->webhookid,
            secret: (string)$record->secret
        );
    }

    public function to_record(): \stdClass {
        $record = new \stdClass();
        $record->id = $this->id;
        $record->paymentaccountid = $this->paymentaccountid;
        $record->webhookid = $this->webhookid;
        $record->secret = $this->secret;
        return $record;
    }
}
