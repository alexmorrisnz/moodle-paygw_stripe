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
 * Various helper methods for interacting with the Stripe API
 *
 * @package    paygw_stripe
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_stripe;

use Stripe\StripeClient;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../.extlib/stripe-php/init.php');

/**
 * The helper class for Stripe payment gateway.
 *
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stripe_helper {

    /**
     * @var StripeClient Secret API key (Do not publish).
     */
    private $stripe;
    /**
     * @var string Public API key.
     */
    private $apikey;

    /**
     * Initialise the Stripe API client.
     *
     * @param string $apikey
     * @param string $secretkey
     */
    public function __construct(string $apikey, string $secretkey) {
        $this->apikey = $apikey;
        $this->stripe = new StripeClient([
                "api_key" => $secretkey
        ]);
    }

    /**
     * Create a payment intent and return with the checkout session id.
     *
     * @param object $config
     * @param string $currency
     * @param string $description
     * @param float $cost
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @return string
     * @throws \Stripe\Exception\ApiErrorException
     */
    public function generate_payment(object $config, string $currency, string $description, float $cost, string $component,
            string $paymentarea, string $itemid): string {
        global $CFG;
        $price = $this->stripe->prices->create([
                'currency' => strtolower($currency),
                'product_data' => [
                        'name' => $description
                ],
                'unit_amount' => $cost * 100
        ]);
        $checkoutsession = $this->stripe->checkout->sessions->create([
                'success_url' => $CFG->wwwroot . '/payment/gateway/stripe/process.php?component=' . $component . '&paymentarea=' .
                        $paymentarea . '&itemid=' . $itemid . '&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $CFG->wwwroot . '/payment/gateway/stripe/cancelled.php?component=' . $component . '&paymentarea=' .
                        $paymentarea . '&itemid=' . $itemid,
                'payment_method_types' => $config->paymentmethods,
                'mode' => 'payment',
                'line_items' => [[
                        'price' => $price,
                        'quantity' => 1
                ]]
        ]);
        return $checkoutsession->id;
    }

    /**
     * Check if a checkout session has been paid
     *
     * @param $sessionid
     * @return bool
     * @throws \Stripe\Exception\ApiErrorException
     */
    public function is_paid($sessionid) {
        $session = $this->stripe->checkout->sessions->retrieve($sessionid);
        return $session->payment_status === 'paid';
    }

}
