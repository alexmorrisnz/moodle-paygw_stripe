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
 * A purchase awaiting billing details, or its Stripe invoice.
 *
 * Amounts are stored in Stripe minor currency units. Billing details stay in Stripe.
 * @package paygw_stripe
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class invoice implements mappable_model {
    use mapping_helper;

    /**
     * Construct a stored purchase.
     * @param int|null $id Stored id.
     * @param int $userid Stored userid.
     * @param int $paymentaccountid Stored paymentaccountid.
     * @param string $customerid Stored customerid.
     * @param string $component Stored component.
     * @param string $paymentarea Stored paymentarea.
     * @param int $itemid Stored itemid.
     * @param int $amount Stored amount.
     * @param string $currency Stored currency.
     * @param string $description Stored description.
     * @param bool $automatictax Stored automatictax.
     * @param string $taxbehavior Stored taxbehavior.
     * @param string $tokenhash Stored tokenhash.
     * @param int $timecreated Stored timecreated.
     * @param int $timeexpires Stored timeexpires.
     * @param string $status Stored status.
     * @param bool $delivered Stored delivered.
     * @param int $timemodified Stored timemodified.
     * @param string|null $invoiceid Stored invoiceid.
     * @param string|null $invoiceitemid Stored invoiceitemid.
     * @param string|null $portalsessionid Stored portalsessionid.
     * @param string|null $priceid Stored priceid.
     * @param int|null $amounttotal Stored amounttotal.
     * @param int|null $paymentid Stored paymentid.
     * @param int|null $timeconfirmed Stored timeconfirmed.
     * @param string $emailstatus Whether Stripe has accepted the invoice email request.
     * @param int|null $timeemailstarted First attempt to send the invoice email.
     * @param int|null $timeemailsent Time Stripe accepted the invoice email request.
     * @param string|null $banktransfercountry EUR bank country snapshot; null for legacy or non-EUR purchases.
     * @param string $paymentmethodstatus Legacy, pending, applying or ready payment configuration.
     * @param string|null $paymentmethods JSON snapshot of resolved Stripe methods plus bank transfer.
     */
    public function __construct(
        /** @var int|null */
        public ?int $id,
        /** @var int */
        public int $userid,
        /** @var int */
        public int $paymentaccountid,
        /** @var string */
        public string $customerid,
        /** @var string */
        public string $component,
        /** @var string */
        public string $paymentarea,
        /** @var int */
        public int $itemid,
        /** @var int */
        public int $amount,
        /** @var string */
        public string $currency,
        /** @var string */
        public string $description,
        /** @var bool */
        public bool $automatictax,
        /** @var string */
        public string $taxbehavior,
        /** @var string */
        public string $tokenhash,
        /** @var int */
        public int $timecreated,
        /** @var int */
        public int $timeexpires,
        /** @var string */
        public string $status = 'billing',
        /** @var bool */
        public bool $delivered = false,
        /** @var int */
        public int $timemodified = 0,
        /** @var string|null */
        public ?string $invoiceid = null,
        /** @var string|null */
        public ?string $invoiceitemid = null,
        /** @var string|null */
        public ?string $portalsessionid = null,
        /** @var string|null */
        public ?string $priceid = null,
        /** @var int|null */
        public ?int $amounttotal = null,
        /** @var int|null */
        public ?int $paymentid = null,
        /** @var int|null */
        public ?int $timeconfirmed = null,
        /** @var string */
        public string $emailstatus = 'pending',
        /** @var int|null */
        public ?int $timeemailstarted = null,
        /** @var int|null */
        public ?int $timeemailsent = null,
        /** @var string|null */
        public ?string $banktransfercountry = null,
        /** @var string */
        public string $paymentmethodstatus = 'legacy',
        /** @var string|null */
        public ?string $paymentmethods = null,
    ) {
    }

    /**
     * Hydrate a database record.
     * @param \stdClass $record
     * @return self
     */
    public static function from_record(\stdClass $record): self {
        return new self(
            id: self::nullable_int_field($record, 'id'),
            userid: (int)$record->userid,
            paymentaccountid: (int)$record->paymentaccountid,
            customerid: (string)$record->customerid,
            component: (string)$record->component,
            paymentarea: (string)$record->paymentarea,
            itemid: (int)$record->itemid,
            amount: (int)$record->amount,
            currency: (string)$record->currency,
            description: (string)$record->description,
            automatictax: (bool)$record->automatictax,
            taxbehavior: (string)$record->taxbehavior,
            tokenhash: (string)$record->tokenhash,
            timecreated: (int)$record->timecreated,
            timeexpires: (int)$record->timeexpires,
            status: (string)$record->status,
            delivered: (bool)$record->delivered,
            timemodified: (int)$record->timemodified,
            invoiceid: self::nullable_string_field($record, 'invoiceid'),
            invoiceitemid: self::nullable_string_field($record, 'invoiceitemid'),
            portalsessionid: self::nullable_string_field($record, 'portalsessionid'),
            priceid: self::nullable_string_field($record, 'priceid'),
            amounttotal: self::nullable_int_field($record, 'amounttotal'),
            paymentid: self::nullable_int_field($record, 'paymentid'),
            timeconfirmed: self::nullable_int_field($record, 'timeconfirmed'),
            emailstatus: (string)($record->emailstatus ?? 'legacy'),
            timeemailstarted: self::nullable_int_field($record, 'timeemailstarted'),
            timeemailsent: self::nullable_int_field($record, 'timeemailsent'),
            banktransfercountry: self::nullable_string_field($record, 'banktransfercountry'),
            paymentmethodstatus: (string)($record->paymentmethodstatus ?? 'legacy'),
            paymentmethods: self::nullable_string_field($record, 'paymentmethods'),
        );
    }

    /**
     * Serialise this purchase.
     * @return \stdClass
     */
    public function to_record(): \stdClass {
        $record = (object)get_object_vars($this);
        $record->automatictax = (int)$this->automatictax;
        $record->delivered = (int)$this->delivered;
        return $record;
    }
}
