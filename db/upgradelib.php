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
 * Upgrade functions for paygw_stripe.
 *
 * @package    paygw_stripe
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../.extlib/stripe-php/init.php');

use core_payment\account;
use Stripe\Stripe;
use Stripe\StripeClient;

/**
 * Update all webhook events.
 *
 * @param array $events
 * @return void
 */
function paygw_stripe_update_webhooks(array $events) {
    global $DB;

    $gateways = $DB->get_records('payment_gateways', ['gateway' => 'stripe']);
    foreach ($gateways as $gatewayrecord) {
        $account = new account($gatewayrecord->accountid);
        $gateway = $account->get_gateways(false)['stripe'] ?? null;
        if ($gateway != null) {
            $config = $gateway->get_configuration();
            try {
                $stripe = new StripeClient([
                    "api_key" => $config['secretkey']
                ]);
                Stripe::setAppInfo(
                    'Moodle Stripe Payment Gateway',
                    get_config('paygw_stripe')->version,
                    'https://github.com/alexmorrisnz/moodle-paygw_stripe'
                );
                $webhooks = $DB->get_records('paygw_stripe_webhooks', ['paymentaccountid' => $account->get('id')]);
                foreach ($webhooks as $webhookrecord) {
                    $stripe->webhookEndpoints->update($webhookrecord->webhookid, ['enabled_events' => $events]);
                }
            } catch (Exception $ignored) {
                // Ignore errors, the api keys we are given may be wrong.
                continue;
            }
        }
    }
}
