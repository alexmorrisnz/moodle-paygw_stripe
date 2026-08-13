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

use paygw_stripe\stripe_helper;
use Stripe\StripeClient;

/**
 * Stripe service factory.
 *
 * @copyright 2026 Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class stripe_service_factory {
    public function __construct(
        private string $apikey,
        private string $secretkey,
    ) {
    }

    private function stripe_client(): StripeClient {
        return new StripeClient([
            'api_key' => $this->secretkey,
            'stripe_version' => stripe_helper::$apiversion,
        ]);
    }

    public function webhook_service(): webhook_service {
        return new webhook_service($this->stripe_client());
    }

    public function product_pricing_service(): product_pricing_service {
        return new product_pricing_service($this->stripe_client());
    }

    public function customer_service(): customer_service {
        return new customer_service($this->stripe_client());
    }

    public function subscription_service(): subscription_service {
        return new subscription_service($this->stripe_client());
    }
}
