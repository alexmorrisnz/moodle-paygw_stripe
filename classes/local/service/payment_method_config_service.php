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

use Stripe\StripeClient;

/**
 * Payment Method Configuration service.
 *
 * @package   paygw_stripe
 * @copyright Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class payment_method_config_service {
    /**
     * @var StripeClient The Stripe API client.
     */
    private $stripe;

    /**
     * Payment Method Configuration service constructor.
     *
     * @param StripeClient $stripe The Stripe API client.
     */
    public function __construct(StripeClient $stripe) {
        $this->stripe = $stripe;
    }

    /**
     * Create a new payment method configuration.
     *
     * @param string $prefix The prefix for the payment method configuration name.
     * @param array $methods The list of payment methods to enable, defaults to Stripe's default.
     * @return string Stripe payment method configuration ID.
     */
    public function create_payment_method_config(string $prefix, array $methods = []): string {
        // If no methods are specified, we do not pass any in, relying on Stripe's default.
        $data = [
            'name' => $prefix . ' paygw_stripe Moodle Plugin',
        ];
        if (!empty($methods)) {
            $allpaymentmethods = [
                'acss_debit',
                'affirm',
                'afterpay_clearpay',
                'alipay',
                'alma',
                'amazon_pay',
                'apple_pay',
                'apple_pay_later',
                'au_becs_debit',
                'bacs_debit',
                'bancontact',
                'billie',
                'bizum',
                'blik',
                'boleto',
                'card',
                'cartes_bancaires',
                'cashapp',
                'crypto',
                'customer_balance',
                'eps',
                'fpx',
                'fr_meal_voucher_conecs',
                'giropay',
                'google_pay',
                'grabpay',
                'ideal',
                'jcb',
                'kakao_pay',
                'klarna',
                'konbini',
                'kr_card',
                'link',
                'mb_way',
                'mobilepay',
                'multibanco',
                'naver_pay',
                'nz_bank_account',
                'oxxo',
                'p24',
                'pay_by_bank',
                'payco',
                'paynow',
                'paypal',
                'paypay',
                'payto',
                'pix',
                'promptpay',
                'revolut_pay',
                'samsung_pay',
                'satispay',
                'scalapay',
                'sepa_debit',
                'sofort',
                'sunbit',
                'swish',
                'twint',
                'upi',
                'us_bank_account',
                'wechat_pay',
                'zip',
            ];

            foreach ($allpaymentmethods as $method) {
                $data[$method] = [
                    'display_preference' => [
                        'preference' => 'off',
                    ],
                ];
            }

            foreach ($methods as $method) {
                $data[$method] = [
                    'display_preference' => [
                        'preference' => 'on',
                    ],
                ];
            }
        }

        return $this->stripe->paymentMethodConfigurations->create($data)->id;
    }

    /**
     * List all enabled payment method configurations.
     *
     * @return array
     * @throws \Stripe\Exception\ApiErrorException
     */
    public function list_payment_method_configs(): array {
        return $this->stripe->paymentMethodConfigurations->all(['active' => true])->data;
    }
}
