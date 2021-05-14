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
 * Contains class for Stripe payment gateway.
 *
 * @package    paygw_stripe
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_stripe;

class gateway extends \core_payment\gateway {
    public static function get_supported_currencies(): array {
        // Only certain currencies are supported based on the users account, but below are all the currencies that the plugin
        // can support as they are given in cents.
        return [
                'USD', 'AED', 'ALL', 'AMD', 'ANG', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BIF', 'BMD', 'BND', 'BSD',
                'BWP', 'BZD', 'CAD', 'CDF', 'CHF', 'CNY', 'DKK', 'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'GBP', 'GEL', 'GIP',
                'GMD', 'GYD', 'HKD', 'HRK', 'HTG', 'IDR', 'ILS', 'ISK', 'JMD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KRW', 'KYD',
                'KZT', 'LBP', 'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRO', 'MVR', 'MWK', 'MXN',
                'MYR', 'MZN', 'NAD', 'NGN', 'NOK', 'NPR', 'NZD', 'PGK', 'PHP', 'PKR', 'PLN', 'QAR', 'RON', 'RSD', 'RUB', 'RWF',
                'SAR', 'SBD', 'SCR', 'SEK', 'SGD', 'SLL', 'SOS', 'SZL', 'THB', 'TJS', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH',
                'UGX', 'UZS', 'VND', 'VUV', 'WST', 'XAF', 'XCD', 'YER', 'ZAR'
        ];
    }

    /**
     * Configuration form for the gateway instance
     *
     * Use $form->get_mform() to access the \MoodleQuickForm instance
     *
     * @param \core_payment\form\account_gateway $form
     */
    public static function add_configuration_to_gateway_form(\core_payment\form\account_gateway $form): void {
        $mform = $form->get_mform();

        $mform->addElement('text', 'apikey', get_string('apikey', 'paygw_stripe'));
        $mform->setType('apikey', PARAM_TEXT);
        $mform->addHelpButton('apikey', 'apikey', 'paygw_stripe');

        $mform->addElement('text', 'secretkey', get_string('secretkey', 'paygw_stripe'));
        $mform->setType('secretkey', PARAM_TEXT);
        $mform->addHelpButton('secretkey', 'secretkey', 'paygw_stripe');

        $paymentmethods = [
                'card' => 'Card',
                'alipay' => 'Alipay',
                'bancontact' => 'Bancontact',
                'eps' => 'EPS',
                'giropay' => 'giropay',
                'ideal' => 'iDEAL',
                'p24' => 'P24',
                'sepa_debit' => 'SEPA Direct Debit',
                'sofort' => 'Sofort',
        ];
        $method = $mform->addElement('select', 'paymentmethods', 'Payment Methods', $paymentmethods);
        $mform->setType('paymentmethods', PARAM_TEXT);
        $mform->setDefault('paymentmethods', 'card');
        $method->setMultiple(true);
    }

    /**
     * Validates the gateway configuration form.
     *
     * @param \core_payment\form\account_gateway $form
     * @param \stdClass $data
     * @param array $files
     * @param array $errors form errors (passed by reference)
     */
    public static function validate_gateway_form(\core_payment\form\account_gateway $form,
            \stdClass $data, array $files, array &$errors): void {
        if ($data->enabled && (empty($data->apikey) || empty($data->secretkey) || empty($data->paymentmethods))) {
            $errors['enabled'] = get_string('gatewaycannotbeenabled', 'payment');
        }
    }
}
