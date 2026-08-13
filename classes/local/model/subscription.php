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
 * Subscription model.
 *
 * @copyright 2026 Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class subscription implements mappable_model {
    use mapping_helper;

    /**
     * Subscription constructor.
     *
     * @param int|null $id
     * @param int $userid
     * @param string $subscriptionid
     * @param string $customerid
     * @param string|null $status
     * @param string $productid
     * @param string $priceid
     */
    public function __construct(
        /** @var int|null */
        public readonly ?int $id,
        /** @var int */
        public readonly int $userid,
        /** @var string */
        public readonly string $subscriptionid,
        /** @var string */
        public readonly string $customerid,
        /** @var string|null */
        public readonly ?string $status,
        /** @var string */
        public readonly string $productid,
        /** @var string */
        public readonly string $priceid,
    ) {
    }

    /**
     * Create a new instance with a different status.
     *
     * @param string|null $status
     * @return self
     */
    public function with_status(?string $status): self {
        return new self(
            id: $this->id,
            userid: $this->userid,
            subscriptionid: $this->subscriptionid,
            customerid: $this->customerid,
            status: $status,
            productid: $this->productid,
            priceid: $this->priceid,
        );
    }

    /**
     * Create a subscription model from a record.
     *
     * @param \stdClass $record
     * @return self
     */
    public static function from_record(\stdClass $record): self {
        return new self(
            id: self::nullable_int_field($record, 'id'),
            userid: (int)$record->userid,
            subscriptionid: (string)$record->subscriptionid,
            customerid: (string)$record->customerid,
            status: self::nullable_string_field($record, 'status'),
            productid: (string)$record->productid,
            priceid: (string)$record->priceid,
        );
    }

    /**
     * Convert the subscription model to a record.
     *
     * @return \stdClass
     */
    public function to_record(): \stdClass {
        $record = new \stdClass();
        $record->id = $this->id;
        $record->userid = $this->userid;
        $record->subscriptionid = $this->subscriptionid;
        $record->customerid = $this->customerid;
        $record->status = $this->status;
        $record->productid = $this->productid;
        $record->priceid = $this->priceid;
        return $record;
    }
}
