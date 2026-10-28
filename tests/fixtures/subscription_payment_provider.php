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

namespace paygw_stripe\payment;

/**
 * Fee delivery with controllable failure after enrolment for transaction tests.
 *
 * @package paygw_stripe
 * @category test
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service_provider extends \enrol_fee\payment\service_provider {
    /** @var string Failure mode, empty for success. */
    public static string $failure = '';

    /**
     * Deliver the enrolment before simulating a callback failure.
     *
     * @param string $paymentarea Payment area
     * @param int $instanceid Fee instance
     * @param int $paymentid Recorded payment
     * @param int $userid Purchasing user
     * @return bool
     */
    public static function deliver_order(string $paymentarea, int $instanceid, int $paymentid, int $userid): bool {
        parent::deliver_order($paymentarea, $instanceid, $paymentid, $userid);
        if (self::$failure === 'exception') {
            throw new \moodle_exception('subscriptiondeliveryfailed', 'paygw_stripe');
        }
        return self::$failure !== 'false';
    }
}
