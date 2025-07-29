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

$string['allowpromotioncodes'] = 'Allow Promotion Codes';
$string['alreadydeliveredcourse'] = 'This course is already delivered to you, if you believe there has been an error contact your site administrator.';
$string['anchoredbilling'] = 'Use the start of the subscription interval as a fixed billing date.';
$string['anchoredbilling_help'] =
    'E.g. For a monthly subscription, billing will be done every 1st of the month. If a user subscribes in the middle of the month, they will be charged a prorated amount covering from the registration day to the end of the month';
$string['apikey'] = 'API Key';
$string['apikey_help'] = 'The API key that we use to identifier ourselves with Stripe';
$string['apiwebhookerror'] = 'There was an error while creating a webhook using the given API keys.';
$string['cancel'] = 'Cancel';
$string['cancelsubscription'] = 'Cancel subscription';
$string['cancelsubscriptionconfirm'] = 'Are you sure you wish to cancel this subscription?';
$string['cancelsubscriptions'] = 'Change Subscriptions';
$string['collectbillingaddress'] = 'Collect billing address';
$string['collectbillingaddress_desc'] = 'Require billing address in checkout';
$string['customerdescription'] = 'Moodle User ID: {$a}';
$string['customsubscriptioninterval'] = 'Custom Subscription Period';
$string['customsubscriptioninterval:day'] = 'Day';
$string['customsubscriptioninterval:month'] = 'Month';
$string['customsubscriptioninterval:week'] = 'Week';
$string['customsubscriptioninterval:year'] = 'Year';
$string['customsubscriptionintervalcount'] = 'Custom Subscription Period Interval';
$string['customsubscriptionintervalcount_help'] = '';
$string['defaulttaxbehavior'] = 'Default tax behavior';
$string['defaulttaxbehavior_help'] = 'Default behavior of tax (inclusive, exclusive). Changeable in Stripe dashboard.';
$string['enableautomatictax'] = 'Enable automatic tax';
$string['enableautomatictax_desc'] = 'Automatic tax must be enabled and configured in the Stripe dashboard.';
$string['failedtosetdefaultpaymentmethod'] = 'Failed to set up a payment method for subscription, please try again.';
$string['fee'] = 'Fee';
$string['gatewaydescription'] = 'Stripe is an authorised payment gateway provider for processing credit card transactions.';
$string['gatewayname'] = 'Stripe';
$string['invoicecreation'] = 'Automatic Invoices';
$string['invoicecreation_desc'] = 'Generate post-purchase invoice for one-time payments';

$string['messageprovider:payment_failed'] = 'Failed delayed payment notification';
$string['messageprovider:payment_successful'] = 'Successful delayed payment confirmation notification';

$string['payment:failed:message'] = 'Your payment failed to clear, please check your payment details and try again.';
$string['payment:failed:subject'] = 'Payment failed';
$string['payment:successful:message'] = 'Your payment was successful, you can now visit {$a->url}';
$string['payment:successful:subject'] = 'Payment successful';

$string['paymentcancelled'] = 'Payment was cancelled';

$string['paymentmethod:alipay'] = 'Alipay';
$string['paymentmethod:bancontact'] = 'Bancontact';
$string['paymentmethod:card'] = 'Card';
$string['paymentmethod:eps'] = 'EPS';
$string['paymentmethod:giropay'] = 'giropay';
$string['paymentmethod:ideal'] = 'iDEAL';
$string['paymentmethod:klarna'] = 'Klarna';
$string['paymentmethod:nz_bank_account'] = 'NZ BECS Direct Debit';
$string['paymentmethod:p24'] = 'P24';
$string['paymentmethod:sepa_debit'] = 'SEPA Direct Debit';
$string['paymentmethod:upi'] = 'UPI';
$string['paymentmethod:wechat_pay'] = 'WeChat Pay';

$string['paymentmethods'] = 'Payment Methods';
$string['paymentpending'] = 'Payment is pending, you will be enrolled when the payment has cleared.';
$string['paymentsuccessful'] = 'Payment was successful';
$string['paymenttype'] = 'Payment Type';
$string['paymenttype:onetime'] = 'One Time';
$string['paymenttype:subscription'] = 'Subscription';
$string['pluginname'] = 'Stripe';
$string['pluginname_desc'] = 'The Stripe plugin allows you to receive payments via Stripe.';

$string['privacy:metadata:stripe_checkout_sessions'] = 'Stores payment checkout data to track payment history';
$string['privacy:metadata:stripe_checkout_sessions:userid'] = 'Moodle user ID';
$string['privacy:metadata:stripe_customers'] = 'Stores the relation from Moodle users to Stripe customer objects';
$string['privacy:metadata:stripe_customers:customerid'] = 'Customer ID returned from Stripe';
$string['privacy:metadata:stripe_customers:userid'] = 'Moodle user ID';
$string['privacy:metadata:stripe_subscriptions'] =
    'Stores the relation from subscriptions in Moodle to Stripe subscription objects';
$string['privacy:metadata:stripe_subscriptions:userid'] = 'Moodle user ID';

$string['product'] = 'Product';
$string['profilecat'] = 'Stripe Payment Subscriptions';
$string['scheduledrenewal'] = 'Scheduled Renewal';
$string['secretkey'] = 'Secret Key';
$string['secretkey_help'] = 'Secret key to authenticate with Stripe';
$string['status'] = 'Status';
$string['stripeaccount'] = 'Stripe account ID';
$string['stripeaccount_help'] = 'For creating the direct charge branding page';
$string['subscriptionerror'] = 'There was an error creating the subscription, please contact the site administrator for help';
$string['subscriptioninterval'] = 'Subscription Period';

$string['subscriptionperiod:custom'] = 'Custom';
$string['subscriptionperiod:daily'] = 'Daily';
$string['subscriptionperiod:every3months'] = 'Every 3 Months';
$string['subscriptionperiod:every6months'] = 'Every 6 Months';
$string['subscriptionperiod:monthly'] = 'Monthly';
$string['subscriptionperiod:weekly'] = 'Weekly';
$string['subscriptionperiod:yearly'] = 'Yearly';

$string['subscriptions'] = 'Subscriptions';
$string['subscriptionssubheading'] =
    'This page lists the subscriptions you have purchased. You can cancel subscriptions here, cancellations will be processed immediately and you will not be able to enter the course again.';

$string['subscriptionstatus:active'] = 'Active';
$string['subscriptionstatus:canceled'] = 'Cancelled';
$string['subscriptionstatus:incomplete'] = 'Incomplete';
$string['subscriptionstatus:incomplete_expired'] = 'Expired';
$string['subscriptionstatus:past_due'] = 'Payment Past Due';
$string['subscriptionstatus:paused'] = 'Paused';
$string['subscriptionstatus:trialing'] = 'Trialing';
$string['subscriptionstatus:unpaid'] = 'Unpaid';

$string['subscriptionsuccessful'] = 'Successfully subscribed. You can manage your Stripe Payment subscriptions from your profile page.';

$string['taxbehavior:exclusive'] = 'Exclusive';
$string['taxbehavior:inclusive'] = 'Inclusive';

$string['trialperiod'] = 'Trial Period';
$string['trialperiod_help'] = 'E.g. the first interval is free. If the subscription is created on the 24th April, a month is free and billing starts on the 24th May.<br/>
    If billing happens at the start of the interval (e.g. on the 1st of the month), the pro-rated amount is waved. If a user registers on the 24th April, April is free and billing starts on the 1st May';
$string['updatepaymentmethod'] = 'Update Payment Method';
