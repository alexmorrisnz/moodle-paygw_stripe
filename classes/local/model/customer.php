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
 * Customer model.
 *
 * @package   paygw_stripe
 * @copyright Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class customer implements mappable_model {
    use mapping_helper;

    /**
     * Customer constructor.
     *
     * @param int|null $id
     * @param int $userid
     * @param string $customerid
     */
    public function __construct(
        /** @var int|null */
        public readonly ?int $id,
        /** @var int */
        public readonly int $userid,
        /** @var string */
        public readonly string $customerid,
    ) {
    }

    /**
     * Create a customer model from a record.
     *
     * @param \stdClass $record
     * @return self
     */
    public static function from_record(\stdClass $record): self {
        return new self(
            id: self::nullable_int_field($record, 'id'),
            userid: (int)$record->userid,
            customerid: (string)$record->customerid,
        );
    }

    /**
     * Convert the customer model to a record.
     *
     * @return \stdClass
     */
    public function to_record(): \stdClass {
        $record = new \stdClass();
        $record->id = $this->id;
        $record->userid = $this->userid;
        $record->customerid = $this->customerid;
        return $record;
    }
}
