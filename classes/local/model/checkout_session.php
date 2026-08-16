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
 * Checkout session model.
 *
 * @package   paygw_stripe
 * @copyright Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class checkout_session implements mappable_model {
    use mapping_helper;

    /**
     * Checkout session constructor.
     *
     * @param int|null $id
     * @param int $userid
     * @param string|null $checkoutsessionid
     * @param string|null $paymentintent
     * @param string $customerid
     * @param int $amounttotal
     * @param string|null $paymentstatus
     * @param string|null $status
     * @param string $productid
     */
    public function __construct(
        /** @var int|null */
        public readonly ?int $id,
        /** @var int */
        public readonly int $userid,
        /** @var string|null */
        public readonly ?string $checkoutsessionid,
        /** @var string|null */
        public readonly ?string $paymentintent,
        /** @var string */
        public readonly string $customerid,
        /** @var int */
        public readonly int $amounttotal,
        /** @var string|null */
        public readonly ?string $paymentstatus,
        /** @var string|null */
        public readonly ?string $status,
        /** @var string */
        public readonly string $productid,
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
            checkoutsessionid: $this->checkoutsessionid,
            paymentintent: $this->paymentintent,
            customerid: $this->customerid,
            amounttotal: $this->amounttotal,
            paymentstatus: $this->paymentstatus,
            status: $status,
            productid: $this->productid,
        );
    }

    /**
     * Create a new instance with a different payment status.
     *
     * @param string|null $paymentstatus
     * @return self
     */
    public function with_paymentstatus(?string $paymentstatus): self {
        return new self(
            id: $this->id,
            userid: $this->userid,
            checkoutsessionid: $this->checkoutsessionid,
            paymentintent: $this->paymentintent,
            customerid: $this->customerid,
            amounttotal: $this->amounttotal,
            paymentstatus: $paymentstatus,
            status: $this->status,
            productid: $this->productid,
        );
    }

    /**
     * Create a checkout session model from a record.
     *
     * @param \stdClass $record
     * @return self
     */
    public static function from_record(\stdClass $record): self {
        return new self(
            id: self::nullable_int_field($record, 'id'),
            userid: (int)$record->userid,
            checkoutsessionid: self::nullable_string_field($record, 'checkoutsessionid'),
            paymentintent: self::nullable_string_field($record, 'paymentintent'),
            customerid: (string)$record->customerid,
            amounttotal: (int)$record->amounttotal,
            paymentstatus: self::nullable_string_field($record, 'paymentstatus'),
            status: self::nullable_string_field($record, 'status'),
            productid: (string)$record->productid,
        );
    }

    /**
     * Convert the checkout session model to a record.
     *
     * @return \stdClass
     */
    public function to_record(): \stdClass {
        $record = new \stdClass();
        $record->id = $this->id;
        $record->userid = $this->userid;
        $record->checkoutsessionid = $this->checkoutsessionid;
        $record->paymentintent = $this->paymentintent;
        $record->customerid = $this->customerid;
        $record->amounttotal = $this->amounttotal;
        $record->paymentstatus = $this->paymentstatus;
        $record->status = $this->status;
        $record->productid = $this->productid;
        return $record;
    }
}
