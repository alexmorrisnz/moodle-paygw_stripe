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
 * Process payment, deliver the order to the user.
 *
 * @package    paygw_stripe
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_payment\helper;
use paygw_stripe\stripe_helper;

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once(__DIR__ . '/.extlib/stripe-php/init.php');

require_login();

$component = required_param('component', PARAM_ALPHANUMEXT);
$paymentarea = required_param('paymentarea', PARAM_ALPHANUMEXT);
$itemid = required_param('itemid', PARAM_INT);
$sessionid = required_param('session_id', PARAM_TEXT);

$config = (object) helper::get_gateway_configuration($component, $paymentarea, $itemid, 'stripe');

$stripehelper = new stripe_helper($config->apikey, $config->secretkey);

$stripehelper->save_payment_status($sessionid);
if ($stripehelper->is_paid($sessionid)) {
    // Deliver course.
    $payable = helper::get_payable($component, $paymentarea, $itemid);
    $cost = helper::get_rounded_cost($payable->get_amount(), $payable->get_currency(), helper::get_gateway_surcharge('stripe'));
    $paymentid = helper::save_payment($payable->get_account_id(), $component, $paymentarea,
        $itemid, $USER->id, $cost, $payable->get_currency(), 'stripe');
    helper::deliver_order($component, $paymentarea, $itemid, $paymentid, $USER->id);

    // Find redirection.
    $url = helper::get_success_url($component, $paymentarea, $itemid);
    redirect($url, get_string('paymentsuccessful', 'paygw_stripe'), 0, 'success');
} else if ($stripehelper->is_pending($sessionid)) {
    redirect(new moodle_url('/'), get_string('paymentpending', 'paygw_stripe'));
}
redirect(new moodle_url('/'), get_string('paymentcancelled', 'paygw_stripe'));
