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

namespace paygw_stripe\local\service;

use core_payment\account;
use paygw_stripe\local\repository\invoice_repository;
use paygw_stripe\local\repository\webhook_repository;
use Stripe\Webhook;

/**
 * Resolve invoice webhooks through stored purchases before verifying signatures.
 * @package paygw_stripe
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class invoice_webhook_handler {
    /**
     * Untrusted input is used only to select the stored record and signing secret.
     * No payment/enrolment writes occur until signature verification succeeds.
     * @param string $payload
     * @param string $signature
     * @return bool False for subscription, Checkout or other unmanaged invoices.
     */
    public function handle(string $payload, string $signature): bool {
        $json = json_decode($payload, true);
        $id = $json['data']['object']['id'] ?? null;
        if (!is_string($id) || !($record = (new invoice_repository())->find_by_invoiceid($id))) {
            return false;
        }
        $webhook = (new webhook_repository())->find_by_paymentaccountid($record->paymentaccountid);
        if (!$webhook) {
            throw new \moodle_exception('invoiceunavailable', 'paygw_stripe');
        }
        $event = Webhook::constructEvent($payload, $signature, $webhook->secret);
        $account = new account($record->paymentaccountid);
        $gateway = $account->get_gateways(false)['stripe'] ?? null;
        if (!$gateway) {
            throw new \moodle_exception('invoiceunavailable', 'paygw_stripe');
        }
        $config = $gateway->get_configuration();
        $factory = new stripe_service_factory($config['apikey'], $config['secretkey']);
        return $factory->invoice_service()->process_event($event);
    }
}
