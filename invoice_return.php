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
 * Ordinary/aborted Portal return. Never continues a purchase or creates an invoice.
 * @package paygw_stripe
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_payment\account;
use paygw_stripe\local\repository\invoice_repository;
use paygw_stripe\local\service\stripe_service_factory;

require_once(__DIR__ . '/../../../config.php');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
require_login();
require_sesskey();

$id = required_param('request', PARAM_INT);
$record = (new invoice_repository())->find_by_id($id);
if (!$record || $record->userid !== (int)$USER->id) {
    throw new moodle_exception('invalidinvoicecontinuation', 'paygw_stripe');
}
$account = new account($record->paymentaccountid);
$gateway = $account->get_gateways(false)['stripe'] ?? null;
if ($gateway) {
    $config = $gateway->get_configuration();
    $factory = new stripe_service_factory($config['apikey'], $config['secretkey']);
    $factory->invoice_service()->cancel_billing($id, (int)$USER->id);
}
redirect(new moodle_url('/'), get_string('invoicebillingcancelled', 'paygw_stripe'));
