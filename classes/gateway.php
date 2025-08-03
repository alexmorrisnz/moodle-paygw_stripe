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

use core_payment\form\account_gateway;
use Exception;

/**
 * The gateway class for Stripe payment gateway.
 *
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gateway extends \core_payment\gateway {
    /**
     * The full list of currencies supported by Stripe regardless of account origin country.
     * Only certain currencies are supported based on the users account, the plugin does not account for that
     * when giving the list of supported currencies.
     *
     * {@link https://stripe.com/docs/currencies}
     *
     * @return string[]
     */
    public static function get_supported_currencies(): array {
        return [
            'USD', 'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BGN', 'BIF', 'BMD',
            'BND', 'BOB', 'BRL', 'BSD', 'BWP', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CVE', 'CZK', 'DJF', 'DKK',
            'DOP', 'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG',
            'HUF', 'IDR', 'ILS', 'INR', 'ISK', 'JMD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KRW', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR',
            'LRD', 'LSL', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN',
            'NIO', 'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR',
            'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SOS', 'SRD', 'STD', 'SZL', 'THB', 'TJS', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH',
            'UGX', 'UYU', 'UZS', 'VND', 'VUV', 'WST', 'XAF', 'XCD', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW',
        ];
    }

    /**
     * The list of zero/non-decimal currencies in Stripe.
     *
     * {@link https://stripe.com/docs/currencies#zero-decimal}
     *
     * @return string[]
     */
    public static function get_zero_decimal_currencies(): array {
        return [
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        ];
    }

    /**
     * Configuration form for the gateway instance
     *
     * Use $form->get_mform() to access the \MoodleQuickForm instance
     *
     * @param account_gateway $form
     */
    public static function add_configuration_to_gateway_form(account_gateway $form): void {
        $mform = $form->get_mform();

        $mform->addElement('text', 'apikey', get_string('apikey', 'paygw_stripe'));
        $mform->setType('apikey', PARAM_TEXT);
        $mform->addHelpButton('apikey', 'apikey', 'paygw_stripe');

        $mform->addElement('text', 'secretkey', get_string('secretkey', 'paygw_stripe'));
        $mform->setType('secretkey', PARAM_TEXT);
        $mform->addHelpButton('secretkey', 'secretkey', 'paygw_stripe');

        $paymentmethods = [
            'card' => get_string('paymentmethod:card', 'paygw_stripe'),
            'alipay' => get_string('paymentmethod:alipay', 'paygw_stripe'),
            'bancontact' => get_string('paymentmethod:bancontact', 'paygw_stripe'),
            'eps' => get_string('paymentmethod:eps', 'paygw_stripe'),
            'giropay' => get_string('paymentmethod:giropay', 'paygw_stripe'),
            'ideal' => get_string('paymentmethod:ideal', 'paygw_stripe'),
            'p24' => get_string('paymentmethod:p24', 'paygw_stripe'),
            'sepa_debit' => get_string('paymentmethod:sepa_debit', 'paygw_stripe'),
            'upi' => get_string('paymentmethod:upi', 'paygw_stripe'),
            'wechat_pay' => get_string('paymentmethod:wechat_pay', 'paygw_stripe'),
            'klarna' => get_string('paymentmethod:klarna', 'paygw_stripe'),
            'nz_bank_account' => get_string('paymentmethod:nz_bank_account', 'paygw_stripe'),
        ];
        $method = $mform->addElement('select', 'paymentmethods', get_string('paymentmethods', 'paygw_stripe'), $paymentmethods);
        $mform->setType('paymentmethods', PARAM_TEXT);
        $mform->setDefault('paymentmethods', 'card');
        $method->setMultiple(true);

        $mform->addElement('advcheckbox', 'allowpromotioncodes', get_string('allowpromotioncodes', 'paygw_stripe'));
        $mform->setDefault('allowpromotioncodes', true);

        $mform->addElement(
            'advcheckbox',
            'enableautomatictax',
            get_string('enableautomatictax', 'paygw_stripe'),
            get_string('enableautomatictax_desc', 'paygw_stripe')
        );

        $mform->addElement('select', 'defaulttaxbehavior', get_string('defaulttaxbehavior', 'paygw_stripe'), [
            'exclusive' => get_string('taxbehavior:exclusive', 'paygw_stripe'),
            'inclusive' => get_string('taxbehavior:inclusive', 'paygw_stripe'),
        ]);
        $mform->addHelpButton('defaulttaxbehavior', 'defaulttaxbehavior', 'paygw_stripe');

        $mform->addElement(
            'advcheckbox',
            'collectbillingaddress',
            get_string('collectbillingaddress', 'paygw_stripe'),
            get_string('collectbillingaddress_desc', 'paygw_stripe')
        );
        $mform->setDefault('collectbillingaddress', false);

        $mform->addElement('select', 'type', get_string('paymenttype', 'paygw_stripe'), [
            'onetime' => get_string('paymenttype:onetime', 'paygw_stripe'),
            'subscription' => get_string('paymenttype:subscription', 'paygw_stripe'),
        ]);
        $mform->setType('type', PARAM_TEXT);
        $mform->setDefault('type', 'onetime');

        $mform->addElement(
            'advcheckbox',
            'invoicecreation',
            get_string('invoicecreation', 'paygw_stripe'),
            get_string('invoicecreation_desc', 'paygw_stripe')
        );
        $mform->setDefault('invoicecreation', false);
        $mform->hideIf('invoicecreation', 'type', 'neq', 'onetime');

        $mform->addElement('select', 'subscriptioninterval', get_string('subscriptioninterval', 'paygw_stripe'), [
            'daily' => get_string('subscriptionperiod:daily', 'paygw_stripe'),
            'weekly' => get_string('subscriptionperiod:weekly', 'paygw_stripe'),
            'monthly' => get_string('subscriptionperiod:monthly', 'paygw_stripe'),
            'every3months' => get_string('subscriptionperiod:every3months', 'paygw_stripe'),
            'every6months' => get_string('subscriptionperiod:every6months', 'paygw_stripe'),
            'yearly' => get_string('subscriptionperiod:yearly', 'paygw_stripe'),
            'custom' => get_string('subscriptionperiod:custom', 'paygw_stripe'),
        ]);
        $mform->setType('subscriptioninterval', PARAM_TEXT);
        $mform->setDefault('subscriptioninterval', 'monthly');
        $mform->hideIf('subscriptioninterval', 'type', 'neq', 'subscription');

        $mform->addElement('select', 'customsubscriptioninterval', get_string('customsubscriptioninterval', 'paygw_stripe'), [
            'day' => get_string('customsubscriptioninterval:day', 'paygw_stripe'),
            'week' => get_string('customsubscriptioninterval:week', 'paygw_stripe'),
            'month' => get_string('customsubscriptioninterval:month', 'paygw_stripe'),
            'year' => get_string('customsubscriptioninterval:year', 'paygw_stripe'),
        ]);
        $mform->setType('customsubscriptioninterval', PARAM_TEXT);
        $mform->setDefault('customsubscriptioninterval', 'month');

        $mform->addElement(
            'text',
            'customsubscriptionintervalcount',
            get_string('customsubscriptionintervalcount', 'paygw_stripe')
        );
        $mform->setType('customsubscriptionintervalcount', PARAM_INT);
        $mform->setDefault('customsubscriptionintervalcount', 1);
        $mform->addHelpButton('customsubscriptionintervalcount', 'customsubscriptionintervalcount', 'paygw_stripe');
        $mform->addRule('customsubscriptionintervalcount', null, 'numeric', null, 'client');

        $mform->hideIf('customsubscriptioninterval', 'subscriptioninterval', 'neq', 'custom');
        $mform->hideIf('customsubscriptionintervalcount', 'subscriptioninterval', 'neq', 'custom');

        $mform->addElement(
            'advcheckbox',
            'anchorbilling',
            get_string('anchoredbilling', 'paygw_stripe'),
            get_string('anchoredbilling_help', 'paygw_stripe')
        );
        $mform->hideIf('anchorbilling', 'type', 'neq', 'subscription');

        $mform->addElement(
            'advcheckbox',
            'firstintervalfree',
            get_string('trialperiod', 'paygw_stripe'),
            get_string('trialperiod_help', 'paygw_stripe')
        );
        $mform->hideIf('firstintervalfree', 'type', 'neq', 'subscription');
    }

    /**
     * Validates the gateway configuration form.
     *
     * @param account_gateway $form
     * @param \stdClass $data
     * @param array $files
     * @param array $errors form errors (passed by reference)
     */
    public static function validate_gateway_form(
        account_gateway $form,
        \stdClass $data,
        array $files,
        array &$errors
    ): void {
        global $DB;
        if ($data->enabled && (empty($data->apikey) || empty($data->secretkey) || empty($data->paymentmethods))) {
            $errors['enabled'] = get_string('gatewaycannotbeenabled', 'payment');
        }

        // Very hacky as this shouldn't live in a validation function, but due to Moodle limitations it's placed here.
        // Check if API keys have changed, remove existing webhook and create new.
        $existingdata = $form->get_gateway_persistent()->get_configuration();
        if ($data->apikey != $existingdata['apikey'] || $data->secretkey != $existingdata['secretkey']) {
            $paymentaccountid = $form->get_gateway_persistent()->get_account()->get('id');

            $DB->delete_records('paygw_stripe_webhooks', ['paymentaccountid' => $paymentaccountid]);
            try {
                if (is_string($existingdata['apikey']) && is_string($existingdata['secretkey'])) {
                    $oldhelper = new stripe_helper($existingdata['apikey'], $existingdata['secretkey']);
                    $oldhelper->delete_webhook($paymentaccountid);
                }

                $newhelper = new stripe_helper($data->apikey, $data->secretkey);
                $newhelper->create_webhook($paymentaccountid);
            } catch (Exception $ignored) {
                $errors['apikey'] = get_string('apiwebhookerror', 'paygw_stripe');
            }
        }
    }
}
