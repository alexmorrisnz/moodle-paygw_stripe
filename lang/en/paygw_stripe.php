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
 * Strings for component 'paygw_stripe', language 'en'
 *
 * @package    paygw_stripe
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Stripe';
$string['pluginname_desc'] = 'The Stripe plugin allows you to receive payments via Stripe.';
$string['gatewayname'] = 'Stripe';
$string['apikey'] = 'API Key';
$string['apikey_help'] = 'The API key that we use to identifier ourselves with Stripe';
$string['secretkey'] = 'Secret Key';
$string['secretkey_help'] = 'Secret key to authenticate with Stripe';
$string['paymentmethods'] = 'Payment Methods';
$string['allowpromotioncodes'] = 'Allow Promotion Codes';
$string['gatewaydescription'] = 'Stripe is an authorised payment gateway provider for processing credit card transactions.';
$string['stripeaccount'] = 'Stripe account ID';
$string['stripeaccount_help'] = 'For creating the direct charge branding page';
$string['paymentsuccessful'] = 'Payment was successful';
$string['paymentcancelled'] = 'Payment was cancelled';
$string['customerdescription'] = 'Moodle User ID: {$a}';
$string['enableautomatictax'] = 'Enable automatic tax';
$string['enableautomatictax_desc'] = 'Automatic tax must be enabled and configured in the Stripe dashboard.';
$string['defaulttaxbehavior'] = 'Default tax behavior';
$string['defaulttaxbehavior_help'] = 'Default behavior of tax (inclusive, exclusive). Changeable in Stripe dashboard.';

$string['taxbehavior:exclusive'] = 'Exclusive';
$string['taxbehavior:inclusive'] = 'Inclusive';

$string['paymentmethod:card'] = 'Card';
$string['paymentmethod:alipay'] = 'Alipay';
$string['paymentmethod:bancontact'] = 'Bancontact';
$string['paymentmethod:eps'] = 'EPS';
$string['paymentmethod:giropay'] = 'giropay';
$string['paymentmethod:ideal'] = 'iDEAL';
$string['paymentmethod:p24'] = 'P24';
$string['paymentmethod:sepa_debit'] = 'SEPA Direct Debit';
$string['paymentmethod:sofort'] = 'Sofort';
$string['paymentmethod:upi'] = 'UPI';
$string['paymentmethod:netbanking'] = 'NetBanking';
$string['paymentmethod:wechat_pay'] = 'WeChat Pay';

$string['privacy:metadata:stripe_customers'] = 'Stores the relation from Moodle users to Stripe customer objects';
$string['privacy:metadata:stripe_customers:userid'] = 'Moodle user ID';
$string['privacy:metadata:stripe_customers:customerid'] = 'Customer ID returned from Stripe';

$string['privacy:metadata:stripe_intents'] = 'Stores payment intent data to track payment history';
$string['privacy:metadata:stripe_intents:userid'] = 'Moodle user ID';
