<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Privacy Subsystem implementation for paygw_stripe.
 *
 * @package    paygw_stripe
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_stripe\privacy;

use core_privacy\local\metadata\collection;

/**
 * Privacy Subsystem implementation for paygw_stripe.
 *
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\request\data_provider, \core_privacy\local\metadata\provider {
    /**
     * Returns metadata about this plugin.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored in this plugin.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'paygw_stripe_customers',
            [
                'userid' => 'privacy:metadata:stripe_customers:userid',
                'customerid' => 'privacy:metadata:stripe_customers:customerid',
            ],
            'privacy:metadata:stripe_customers'
        );

        $collection->add_database_table(
            'paygw_stripe_checkout_sessions',
            [
                'userid' => 'privacy:metadata:stripe_checkout_sessions:userid',
            ],
            'privacy:metadata:stripe_checkout_sessions'
        );

        $collection->add_database_table(
            'paygw_stripe_subscriptions',
            [
                'userid' => 'privacy:metadata:stripe_subscriptions:userid',
            ],
            'privacy:metadata:stripe_subscriptions'
        );

        return $collection;
    }
}
