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

use paygw_stripe\local\model\webhook;
use paygw_stripe\local\repository\product_repository;
use paygw_stripe\local\repository\webhook_repository;
use paygw_stripe\stripe_helper;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\WebhookEndpoint;

/**
 * Webhook service.
 *
 * @copyright 2026 Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webhook_service {
    /**
     * @var StripeClient The Stripe API client.
     */
    private $stripe;

    /**
     * @var webhook_repository The webhook repository.
     */
    private $webhookrepository;

    public function __construct(StripeClient $stripe) {
        $this->stripe = $stripe;

        $this->webhookrepository = new webhook_repository();
    }

    /**
     * Find and return webhook endpoint if it exists.
     * Retrieve secret from Moodle database and add to webhook object.
     *
     * @param int $paymentaccountid
     * @return WebhookEndpoint|null
     * @throws ApiErrorException|\dml_exception
     */
    public function get_webhook(int $paymentaccountid): ?WebhookEndpoint {
        if (!($record = $this->webhookrepository->find_by_paymentaccountid($paymentaccountid))) {
            return null;
        }

        if ($webhook = $this->stripe->webhookEndpoints->retrieve($record->webhookid)) {
            // Webhook still exists, lets set the secret and return.
            $webhook->secret = $record->secret;
            return $webhook;
        }

        return null;
    }

    /**
     * Create webhook for given account id if none already exists.
     *
     * @param int $paymentaccountid
     * @return bool True if webhook was created
     * @throws ApiErrorException
     * @throws \dml_exception
     */
    public function create_webhook(int $paymentaccountid): bool {
        global $CFG;

        if ($this->get_webhook($paymentaccountid) != null) {
            return false;
        }

        $webhook = $this->stripe->webhookEndpoints->create([
            'url' => $CFG->wwwroot . '/payment/gateway/stripe/webhook.php',
            'enabled_events' => [
                'checkout.session.completed',
                'checkout.session.async_payment_succeeded',
                'checkout.session.async_payment_failed',
                'customer.subscription.deleted',
                'customer.subscription.updated',
            ],
            'api_version' => stripe_helper::$apiversion,
        ]);

        $record = new webhook(
            null,
            $paymentaccountid,
            $webhook->id,
            $webhook->secret
        );
        $this->webhookrepository->save($record);

        return true;
    }

    /**
     * Delete a webhook record in the database and it's associated Stripe endpoint.
     *
     * @param int $paymentaccountid
     * @return bool
     * @throws ApiErrorException
     * @throws \dml_exception
     */
    public function delete_webhook(int $paymentaccountid): bool {
        if (!($record = $this->webhookrepository->find_by_paymentaccountid($paymentaccountid))) {
            return false;
        }

        $this->webhookrepository->delete($record);

        $this->stripe->webhookEndpoints->delete($record->webhookid);

        return true;
    }
}
