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
$string['paymentpending'] = 'Payment is pending, you will be enrolled when the payment has cleared.';
$string['customerdescription'] = 'Moodle User ID: {$a}';
$string['enableautomatictax'] = 'Enable automatic tax';
$string['enableautomatictax_desc'] = 'Automatic tax must be enabled and configured in the Stripe dashboard.';
$string['defaulttaxbehavior'] = 'Default tax behavior';
$string['defaulttaxbehavior_help'] = 'Default behavior of tax (inclusive, exclusive). Changeable in Stripe dashboard.';
$string['profilecat'] = 'Stripe Payment Subscriptions';
$string['cancelsubscriptions'] = 'Change Subscriptions';
$string['subscriptions'] = 'Subscriptions';
$string['subscriptionsuccessful'] = 'Successfully subscribed';
$string['paymenttype'] = 'Payment Type';
$string['paymenttype:onetime'] = 'One Time';
$string['paymenttype:subscription'] = 'Subscription';
$string['subscriptioninterval'] = 'Subscription Period';
$string['customsubscriptioninterval'] = 'Custom Subscription Period';
$string['customsubscriptionintervalcount'] = 'Custom Subscription Period Interval';
$string['customsubscriptionintervalcount_help'] = '';
$string['anchoredbilling'] = 'Use the start of the current interval as a fixed billing date';
$string['anchoredbilling_help'] =
    'E.g. You subscribe in the middle of the month, you will be billed immediately for the current month';
$string['trialperiod'] = 'Trial Period';
$string['trialperiod_help'] = 'E.g. You register on the 25th of April, April is free and billing starts on the 1st May';
$string['failedtosetdefaultpaymentmethod'] = 'Failed to set up a payment method for subscription, please try again.';
$string['subscriptionerror'] = 'There was an error creating the subscription, please contact the site administrator for help';
$string['cancelsubscription'] = 'Cancel subscription';
$string['cancelsubscriptionconfirm'] = 'Are you sure you wish to cancel this subscription?';
$string['product'] = 'Product';
$string['fee'] = 'Fee';
$string['scheduledrenewal'] = 'Scheduled Renewal';
$string['status'] = 'Status';
$string['updatepaymentmethod'] = 'Update Payment Method';
$string['cancel'] = 'Cancel';
$string['subscriptionssubheading'] =
    'This page lists the subscriptions you have purchased. You can cancel subscriptions here, cancellations will be processed immediately and you will not be able to enter the course again.';

$string['customsubscriptioninterval:day'] = 'Day';
$string['customsubscriptioninterval:week'] = 'Week';
$string['customsubscriptioninterval:month'] = 'Month';
$string['customsubscriptioninterval:year'] = 'Year';

$string['subscriptionperiod:daily'] = 'Daily';
$string['subscriptionperiod:weekly'] = 'Weekly';
$string['subscriptionperiod:monthly'] = 'Monthly';
$string['subscriptionperiod:every3months'] = 'Every 3 Months';
$string['subscriptionperiod:every6months'] = 'Every 6 Months';
$string['subscriptionperiod:yearly'] = 'Yearly';
$string['subscriptionperiod:custom'] = 'Custom';

$string['subscriptionstatus:active'] = 'Active';
$string['subscriptionstatus:past_due'] = 'Payment Past Due';
$string['subscriptionstatus:unpaid'] = 'Unpaid';
$string['subscriptionstatus:canceled'] = 'Cancelled';
$string['subscriptionstatus:incomplete'] = 'Incomplete';
$string['subscriptionstatus:incomplete_expired'] = 'Expired';
$string['subscriptionstatus:trialing'] = 'Trialing';
$string['subscriptionstatus:paused'] = 'Paused';

$string['payment:successful:subject'] = 'Payment successful';
$string['payment:successful:message'] = 'Your payment was successful, you can now visit {$a->url}';
$string['payment:failed:subject'] = 'Payment failed';
$string['payment:failed:message'] = 'Your payment failed to clear, please check your payment details and try again.';

$string['messageprovider:payment_successful'] = 'Successful delayed payment confirmation notification';
$string['messageprovider:payment_failed'] = 'Failed delayed payment notification';

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

$string['privacy:metadata:stripe_subscriptions'] =
    'Stores the relation from subscriptions in Moodle to Stripe subscription objects';
$string['privacy:metadata:stripe_subscriptions:userid'] = 'Moodle user ID';
