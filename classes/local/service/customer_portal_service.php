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

use Stripe\BillingPortal\Session;
use Stripe\StripeClient;

/**
 * Billing identity collection, isolated from Checkout and subscription portals.
 * @package paygw_stripe
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class customer_portal_service {
    /** @var string API version introducing the customer_update direct flow. */
    public const API_VERSION = '2026-08-26.dahlia';

    /** @var StripeClient Stripe API client. */
    private StripeClient $stripe;

    /**
     * Initialise the restricted Portal service.
     * @param StripeClient $stripe
     */
    public function __construct(StripeClient $stripe) {
        $this->stripe = $stripe;
    }

    /**
     * Get or create a dedicated configuration, never modify the default portal.
     * @return string
     */
    private function configuration(): string {
        global $CFG;
        $site = hash('sha256', $CFG->wwwroot);
        $factory = \core\lock\lock_config::get_lock_factory('paygw_stripe');
        $lock = $factory->get_lock('invoice_portal_' . hash('sha256', $this->stripe->getApiKey()), 10);
        if (!$lock) {
            throw new \moodle_exception('invoicebusy', 'paygw_stripe');
        }
        $options = ['stripe_version' => self::API_VERSION];
        $params = [
            'features' => [
                'customer_update' => [
                    'enabled' => true,
                    'allowed_updates' => ['name', 'address', 'email', 'tax_id'],
                ],
                'invoice_history' => ['enabled' => false],
                'payment_method_update' => ['enabled' => false],
                'subscription_cancel' => ['enabled' => false],
                'subscription_update' => ['enabled' => false],
            ],
            'login_page' => ['enabled' => false],
        ];
        try {
            $configs = $this->stripe->billingPortal->configurations->all(['limit' => 100], $options);
            foreach ($configs->autoPagingIterator() as $config) {
                if (
                    ($config->metadata->moodle_site ?? '') === $site &&
                        ($config->metadata->moodle_purpose ?? '') === 'invoice_billing_v1' && !$config->is_default
                ) {
                    $this->stripe->billingPortal->configurations->update(
                        $config->id,
                        $params + ['active' => true],
                        $options
                    );
                    return $config->id;
                }
            }
            $config = $this->stripe->billingPortal->configurations->create($params + [
                'name' => 'Moodle invoice billing details',
                'metadata' => ['moodle_site' => $site, 'moodle_purpose' => 'invoice_billing_v1'],
            ], $options);
            return $config->id;
        } finally {
            $lock->release();
        }
    }

    /**
     * Only Stripe's successful Save redirect receives the secret continuation.
     * The ordinary Return URL carries no continuation token.
     * @param string $customerid
     * @param string $successurl
     * @param string $returnurl
     * @param string $locale
     * @return Session
     */
    public function create_session(string $customerid, string $successurl, string $returnurl, string $locale): Session {
        return $this->stripe->billingPortal->sessions->create([
            'customer' => $customerid,
            'configuration' => $this->configuration(),
            'locale' => $locale,
            'return_url' => $returnurl,
            'flow_data' => [
                'type' => 'customer_update',
                'after_completion' => [
                    'type' => 'redirect',
                    'redirect' => ['return_url' => $successurl],
                ],
            ],
        ], ['stripe_version' => self::API_VERSION]);
    }
}
