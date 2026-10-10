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

/**
 * Stripe checkout session factory for subscription tests.
 *
 * @package    paygw_stripe
 * @category   test
 * @copyright  2026 Alex Morris
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace paygw_stripe\local\service;

use Stripe\Checkout\Session as StripeSession;

/**
 * Builds Stripe checkout session objects used by subscription tests.
 */
final class subscription_test_session_factory {
    /**
     * Builds a subscription mode session with line items and an expanded customer.
     *
     * @param string $sessionid Checkout session identifier.
     * @param string $subscriptionid Stripe subscription identifier.
     * @param string $customerid Stripe customer identifier.
     * @param string $productid Stripe product identifier.
     * @param string $priceid Stripe price identifier.
     * @return StripeSession The generated subscription checkout session.
     */
    public static function subscription_session(
        string $sessionid,
        string $subscriptionid,
        string $customerid,
        string $productid,
        string $priceid
    ): StripeSession {
        return StripeSession::constructFrom([
            'id' => $sessionid,
            'object' => 'checkout.session',
            'mode' => 'subscription',
            'subscription' => $subscriptionid,
            'customer' => ['id' => $customerid, 'object' => 'customer'],
            'line_items' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'li_sub_1',
                    'object' => 'item',
                    'price' => [
                        'id' => $priceid,
                        'object' => 'price',
                        'product' => $productid,
                    ],
                ]],
                'has_more' => false,
                'url' => '/v1/checkout/sessions/' . $sessionid . '/line_items',
            ],
        ]);
    }
}
