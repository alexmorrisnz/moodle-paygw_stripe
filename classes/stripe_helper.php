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
 * Various helper methods for interacting with the Stripe API
 *
 * @package    paygw_stripe
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_stripe;

use core_payment\helper;
use core_payment\local\entities\payable;
use core_user;
use DateInterval;
use DateTime;
use DateTimeZone;
use moodle_url;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Product;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\WebhookEndpoint;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../.extlib/stripe-php/init.php');

/**
 * The helper class for Stripe payment gateway.
 *
 * @copyright  2021 Alex Morris <alex@navra.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stripe_helper {

    /**
     * @var StripeClient Secret API key (Do not publish).
     */
    private $stripe;
    /**
     * @var string Public API key.
     */
    private $apikey;

    /**
     * Initialise the Stripe API client.
     *
     * @param string $apikey
     * @param string $secretkey
     */
    public function __construct(string $apikey, string $secretkey) {
        $this->apikey = $apikey;
        $this->stripe = new StripeClient([
            "api_key" => $secretkey
        ]);
        Stripe::setAppInfo(
            'Moodle Stripe Payment Gateway',
            get_config('paygw_stripe')->version,
            'https://github.com/alexmorrisnz/moodle-paygw_stripe'
        );
    }

    /**
     * Find a product in the database and the corresponding Stripe Product item.
     *
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @return Product|null
     * @throws \dml_exception
     */
    public function get_product(string $component, string $paymentarea, string $itemid): ?Product {
        global $DB;

        if ($record = $DB->get_record('paygw_stripe_products',
            ['component' => $component, 'paymentarea' => $paymentarea, 'itemid' => $itemid])) {
            try {
                return $this->stripe->products->retrieve($record->productid);
            } catch (ApiErrorException $e) {
                // Product exists in Moodle but not in stripe, possibly the keys were switched.
                // Delete product for creation later.
                $DB->delete_records('paygw_stripe_products',
                    ['component' => $component, 'paymentarea' => $paymentarea, 'itemid' => $itemid]);
                return null;
            }
        }
        return null;
    }

    /**
     * Create a product in Stripe and save the ID into the Moodle database.
     *
     * @param string $description
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @return Product
     * @throws ApiErrorException
     * @throws \dml_exception
     */
    public function create_product(string $description, string $component, string $paymentarea, string $itemid): Product {
        global $DB;
        $product = $this->stripe->products->create([
            'name' => $description
        ]);
        $record = new \stdClass();
        $record->productid = $product->id;
        $record->component = $component;
        $record->paymentarea = $paymentarea;
        $record->itemid = $itemid;
        $DB->insert_record('paygw_stripe_products', $record);
        return $product;
    }

    /**
     * Get the first price listed on a product.
     *
     * @param Product $product
     * @param bool $subscription
     * @return Price|null
     */
    public function get_price(Product $product, bool $subscription = false): ?Price {
        try {
            $prices = $this->stripe->prices->all(['product' => $product->id]);
            foreach ($prices as $price) {
                if ($price instanceof Price) {
                    if ($price->active) {
                        if ($subscription && $price->type == 'recurring') {
                            return $price;
                        } else if (!$subscription) {
                            return $price;
                        }
                    }
                }
            }
            return null;
        } catch (ApiErrorException $e) {
            return null;
        }
    }

    /**
     * Create a price against an associated product.
     *
     * @param string $currency Currency
     * @param string $productid Product ID
     * @param float $unitamount Price
     * @param bool $automatictax Toggles insertion of a tax behavior
     * @param string|null $defaultbehavior The default tax behavior for the price, if enabled
     * @param array|null $recurring
     * @return Price
     * @throws ApiErrorException
     */
    public function create_price(string $currency, string $productid, float $unitamount, bool $automatictax,
        ?string $defaultbehavior, array $recurring = null) {
        $pricedata = [
            'currency' => $currency,
            'product' => $productid,
            'unit_amount' => $unitamount,
        ];
        if ($automatictax == 1) {
            $pricedata['tax_behavior'] = $defaultbehavior ?? 'inclusive';
        }
        if (is_array($recurring)) {
            $pricedata['recurring'] = $recurring;
        }
        return $this->stripe->prices->create($pricedata);
    }

    /**
     * Get the stripe Customer object from the corresponding Moodle user id.
     *
     * @param int $userid
     * @return Customer|null
     * @throws \dml_exception
     */
    public function get_customer(int $userid): ?Customer {
        global $DB;
        if (!$record = $DB->get_record('paygw_stripe_customers', ['userid' => $userid])) {
            return null;
        }
        try {
            return $this->stripe->customers->retrieve($record->customerid);
        } catch (ApiErrorException $e) {
            // Customer exists in Moodle but not in stripe, possibly the keys were switched.
            // Delete customer for creation later.
            $DB->delete_records('paygw_stripe_customers', ['userid' => $userid]);
            return null;
        }
    }

    /**
     * Create a Stripe customer object and save the ID and user ID into the database.
     *
     * @param \stdClass $user
     * @return Customer
     * @throws ApiErrorException
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function create_customer($user): Customer {
        global $DB;
        $customer = $this->stripe->customers->create([
            'email' => $user->email,
            'description' => get_string('customerdescription', 'paygw_stripe', $user->id),
        ]);
        $record = new \stdClass();
        $record->userid = $user->id;
        $record->customerid = $customer->id;
        $DB->insert_record('paygw_stripe_customers', $record);
        return $customer;
    }

    /**
     * Creates Stripe product and price objects together.
     * Stores object IDs in Moodle to prevent creating duplicates.
     *
     * @param object $config
     * @param payable $payable
     * @param string $description
     * @param float $cost
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @param array|null $subscription
     * @return array
     * @throws ApiErrorException
     * @throws \dml_exception
     */
    private function create_product_and_price(object $config, payable $payable, string $description, float $cost, string $component,
        string $paymentarea, string $itemid, array $subscription = null) {
        $unitamount = $this->get_unit_amount($cost, $payable->get_currency());
        $currency = strtolower($payable->get_currency());

        if (!$product = $this->get_product($component, $paymentarea, $itemid)) {
            $product = $this->create_product($description, $component, $paymentarea, $itemid);
        }
        if (!$price = $this->get_price($product, is_array($subscription))) {
            $price = $this->create_price($currency, $product->id, $unitamount, $config->enableautomatictax == 1,
                $config->defaulttaxbehavior, $subscription);
        } else {
            // Check if the price details mismatch in any way.
            if ($price->unit_amount != $unitamount || $price->currency != $currency ||
                (is_array($subscription) && $price->type != 'recurring') ||
                (is_array($subscription) && $price->type == 'recurring' &&
                    ($price->recurring->toArray()['interval'] != $subscription['interval'] ||
                        $price->recurring->toArray()['interval_count'] != $subscription['interval_count'])) ||
                ($price->type == 'recurring' && !is_array($subscription))) {
                // We cannot update the price or currency, so we must create a new price.
                $price->updateAttributes(['active' => false]);
                $price->save();
                $price = $this->create_price($currency, $product->id, $unitamount, $config->enableautomatictax == 1,
                    $config->defaulttaxbehavior, $subscription);
            }
            // Set tax behavior if not set already.
            if ($config->enableautomatictax == 1 && (!isset($price->tax_behavior) || $price->tax_behavior === 'unspecified')) {
                $price->updateAttributes(['tax_behavior' => $config->tax_behavior ?? 'inclusive']);
                $price->save();
            }
        }
        if ($product->name != $description) {
            $product->name = $description;
            $product->save();
        }

        return [$product, $price];
    }

    /**
     * Create a payment intent and return with the checkout session id.
     *
     * @param object $config
     * @param payable $payable
     * @param string $description
     * @param float $cost
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @return string
     * @throws ApiErrorException
     */
    public function generate_payment(object $config, payable $payable, string $description, float $cost, string $component,
        string $paymentarea, string $itemid): string {
        global $CFG, $USER;

        // Ensure webhook exists before we potentially use it.
        $this->create_webhook($payable->get_account_id());

        list($product, $price) = $this->create_product_and_price($config, $payable, $description, $cost, $component,
            $paymentarea, $itemid);

        if (!$customer = $this->get_customer($USER->id)) {
            $customer = $this->create_customer($USER);
        }

        $session = $this->stripe->checkout->sessions->create([
            'success_url' => $CFG->wwwroot . '/payment/gateway/stripe/process.php?component=' . $component . '&paymentarea=' .
                $paymentarea . '&itemid=' . $itemid . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $CFG->wwwroot . '/payment/gateway/stripe/cancelled.php?component=' . $component . '&paymentarea=' .
                $paymentarea . '&itemid=' . $itemid,
            'payment_method_types' => $config->paymentmethods,
            'payment_method_options' => [
                'wechat_pay' => [
                    'client' => "web"
                ],
            ],
            'mode' => 'payment',
            'line_items' => [[
                'price' => $price,
                'quantity' => 1
            ]],
            'automatic_tax' => [
                'enabled' => $config->enableautomatictax == 1,
            ],
            'customer' => $customer->id,
            'metadata' => [
                'userid' => $USER->id,
                'username' => $USER->username,
                'firstname' => $USER->firstname,
                'lastname' => $USER->lastname,
                'component' => $component,
                'paymentarea' => $paymentarea,
                'itemid' => $itemid,
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'userid' => $USER->id,
                    'username' => $USER->username,
                    'firstname' => $USER->firstname,
                    'lastname' => $USER->lastname,
                    'component' => $component,
                    'paymentarea' => $paymentarea,
                    'itemid' => $itemid,
                ],
            ],
            'allow_promotion_codes' => $config->allowpromotioncodes == 1,
            'customer_update' => [
                'address' => 'auto',
            ],
        ]);

        return $session->id;
    }

    /**
     * Create a subscription to the course and return with checkout session id.
     *
     * @param object $config
     * @param payable $payable
     * @param string $description
     * @param float $cost
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @param string|null $sessionid
     * @return string|null
     * @throws ApiErrorException
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function generate_subscription(object $config, payable $payable, string $description, float $cost, string $component,
        string $paymentarea, string $itemid, string $sessionid = null): ?string {
        global $CFG, $USER, $DB;

        // Ensure webhook exists before we use it.
        $this->create_webhook($payable->get_account_id());

        $pricedetails = $this->get_subscription_config_price_details($config);

        list($product, $price) = $this->create_product_and_price($config, $payable, $description, $cost, $component,
            $paymentarea, $itemid, $pricedetails);

        if (!$customer = $this->get_customer($USER->id)) {
            $customer = $this->create_customer($USER);
        }

        if ($sessionid != null) {
            $session = $this->stripe->checkout->sessions->retrieve($sessionid, ['expand' => ['setup_intent']]);
            if ($session->status != 'complete') {
                redirect(new moodle_url('/'), get_string('failedtosetdefaultpaymentmethod', 'paygw_stripe'));
            } else {
                $customer->updateAttributes(['invoice_settings' =>
                    ['default_payment_method' => $session->setup_intent->payment_method]]);
                $customer->save();
                $customer->refresh();
            }
        }

        if ($customer->invoice_settings->toArray()['default_payment_method'] == null && $sessionid == null) {
            // Create checkout session to set up default payment source for customer.
            $session = $this->stripe->checkout->sessions->create([
                'success_url' => $CFG->wwwroot . '/payment/gateway/stripe/pay.php?component=' . $component . '&paymentarea=' .
                    $paymentarea . '&itemid=' . $itemid . '&description=' . urlencode($description) .
                    '&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $CFG->wwwroot . '/payment/gateway/stripe/cancelled.php?component=' . $component . '&paymentarea=' .
                    $paymentarea . '&itemid=' . $itemid,
                'payment_method_types' => $config->paymentmethods,
                'mode' => 'setup',
                'automatic_tax' => [
                    'enabled' => $config->enableautomatictax == 1,
                ],
                'customer' => $customer->id,
                'metadata' => [
                    'userid' => $USER->id,
                    'username' => $USER->username,
                    'firstname' => $USER->firstname,
                    'lastname' => $USER->lastname,
                    'component' => $component,
                    'paymentarea' => $paymentarea,
                    'itemid' => $itemid,
                ],
                'customer_update' => [
                    'address' => 'auto',
                ],
            ]);
            return $session->id;
        }

        $subscriptiondata = [
            'customer' => $customer->id,
            'items' => [[
                'price' => $price,
                'quantity' => 1,
            ]],
            'automatic_tax' => [
                'enabled' => $config->enableautomatictax == 1,
            ],
            'payment_settings' => [
                'payment_method_types' => $config->paymentmethods,
            ],
            'collection_method' => 'charge_automatically',
            'metadata' => [
                'userid' => $USER->id,
                'component' => $component,
                'paymentarea' => $paymentarea,
                'itemid' => $itemid,
            ],
        ];

        if ($config->anchorbilling) {
            list ($date, $nextdate) = $this->get_anchor_billing_dates($config);

            $subscriptiondata['backdate_start_date'] = $date->getTimestamp();
            $subscriptiondata['billing_cycle_anchor'] = $nextdate->getTimestamp();

            if ($config->firstintervalfree) {
                $subscriptiondata['trial_end'] = $nextdate->getTimestamp();
            }
        }
        if ($config->firstintervalfree && !$config->anchorbilling) {
            $subscriptiondata['trial_end'] = $this->get_trial_end_date($config)->getTimestamp();
        }

        $subscription = $this->stripe->subscriptions->create($subscriptiondata);

        if (!in_array($subscription->status, ['incomplete', 'incomplete_expired', 'canceled'])) {
            $datum = new \stdClass();
            $datum->userid = $USER->id;
            $datum->subscriptionid = $subscription->id;
            $datum->customerid = $customer->id;
            $datum->status = $subscription->status;
            $datum->productid = $subscription->items->first()->price->product;
            $datum->priceid = $subscription->items->first()->price->id;

            $DB->insert_record('paygw_stripe_subscriptions', $datum);

            $paymentid = helper::save_payment($payable->get_account_id(), $component, $paymentarea,
                $itemid, $USER->id, $cost, $payable->get_currency(), 'stripe');
            helper::deliver_order($component, $paymentarea, $itemid, $paymentid, $USER->id);

            // Find redirection.
            $url = helper::get_success_url($component, $paymentarea, $itemid);
            redirect($url, get_string('subscriptionsuccessful', 'paygw_stripe'), 0, 'success');
        } else {
            redirect(new moodle_url('/'), get_string('subscriptionerror', 'paygw_stripe'));
        }

        return null;
    }

    /**
     * Check if a checkout session has been paid
     *
     * @param string $sessionid Stripe session ID
     * @return bool
     * @throws ApiErrorException
     */
    public function is_paid(string $sessionid): bool {
        $session = $this->stripe->checkout->sessions->retrieve($sessionid);
        return $session->payment_status === 'paid';
    }

    /**
     * Check if a checkout session is pending payment.
     *
     * @param string $sessionid Stripe session ID
     * @return bool
     * @throws ApiErrorException
     */
    public function is_pending(string $sessionid): bool {
        // Check payment intent here as the session status is a simple pass/fail that doesn't include processing.
        $session = $this->stripe->checkout->sessions->retrieve($sessionid, ['expand' => ['payment_intent']]);
        return $session->payment_intent->status === 'processing';
    }

    /**
     * Convert the cost into the unit amount accounting for zero-decimal currencies.
     *
     * @param float $cost
     * @param string $currency
     * @return float
     */
    public function get_unit_amount(float $cost, string $currency): float {
        if (in_array(strtoupper($currency), gateway::get_zero_decimal_currencies())) {
            return $cost;
        }
        return $cost * 100;
    }

    /**
     * Get localised string of a cost
     *
     * @param float $cost
     * @param string $currency
     * @return string
     */
    public function get_localised_cost(float $cost, string $currency): string {
        if (!in_array(strtoupper($currency), gateway::get_zero_decimal_currencies())) {
            $cost = $cost / 100;
        }

        $locale = get_string('localecldr', 'langconfig');
        $fmt = \NumberFormatter::create($locale, \NumberFormatter::CURRENCY);
        return numfmt_format_currency($fmt, $cost, $currency);
    }

    /**
     * Retrieve Stipe subscription details and save in Moodle based on Stripe checkout session.
     *
     * @param Session $session
     * @return void
     * @throws \dml_exception
     */
    private function save_subscription(Session $session) {
        global $DB, $USER;

        $subscription = $this->stripe->subscriptions->retrieve($session->subscription);

        $datum = $DB->get_record('paygw_stripe_subscriptions', ['subscriptionid' => $session->subscription]);
        if ($datum != null) {
            $datum->status = $subscription->status;
            $DB->update_record('paygw_stripe_subscriptions', $datum);
            return;
        }

        $datum = new \stdClass();
        $datum->userid = $USER->id;
        $datum->subscriptionid = $session->subscription;
        $datum->customerid = $session->customer->id;
        $datum->status = $subscription->status;
        $datum->productid = $session->line_items->first()->price->product;
        $datum->priceid = $session->line_items->first()->price->id;

        $DB->insert_record('paygw_stripe_subscriptions', $datum);
    }

    /**
     * Save payment intent status with customer and product details.
     *
     * @param Session $session
     * @return void
     * @throws \dml_exception
     */
    private function save_payment_intent(Session $session) {
        global $DB, $USER;

        $intent = $DB->get_record('paygw_stripe_intents', ['paymentintent' => $session->payment_intent]);
        if ($intent != null) {
            $intent->status = $session->status;
            $intent->paymentstatus = $session->payment_status;
            $DB->update_record('paygw_stripe_intents', $intent);
            return;
        }

        $intent = new \stdClass();
        $intent->userid = $USER->id;
        $intent->paymentintent = $session->payment_intent;
        $intent->customerid = $session->customer->id;
        $intent->amounttotal = $session->amount_total;
        $intent->paymentstatus = $session->payment_status;
        $intent->status = $session->status;
        $intent->productid = $session->line_items->first()->price->product;

        $DB->insert_record('paygw_stripe_intents', $intent);
    }

    /**
     * Saves the payment status
     *
     * @param string $sessionid
     * @return void
     * @throws ApiErrorException|\dml_exception
     */
    public function save_payment_status(string $sessionid) {
        $session = $this->stripe->checkout->sessions->retrieve($sessionid, ['expand' => ['line_items', 'customer']]);

        if ($session->mode == 'subscription') {
            $this->save_subscription($session);
        } else {
            $this->save_payment_intent($session);
        }
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
        global $DB;

        if (!($record = $DB->get_record('paygw_stripe_webhooks', ['paymentaccountid' => $paymentaccountid]))) {
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
        global $CFG, $DB;

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
        ]);

        $datum = new \stdClass();
        $datum->paymentaccountid = $paymentaccountid;
        $datum->webhookid = $webhook->id;
        $datum->secret = $webhook->secret;
        $DB->insert_record('paygw_stripe_webhooks', $datum);

        return true;
    }

    /**
     * Process stripe payment events
     *
     * @param Event $event
     * @param array $metadata Array containing component, paymentarea, and itemid values set.
     * @return bool True if stripe data was valid, false otherwise.
     * @throws ApiErrorException|\dml_exception
     */
    public function process_stripe_event(Event $event, array $metadata): bool {
        global $DB;

        if (!isset($event->data->object)) {
            return false;
        }

        switch ($event->type) {
            // Process an async payment event.
            // Deliver the course if payment was successful or notify the user the payment failed.
            case 'checkout.session.async_payment_succeeded':
                // Events are sent to all subscribed webhooks, verify we are the correct receipt for this event.
                $session = $this->stripe->checkout->sessions->retrieve($event->data->object->id, ['expand' => ['payment_intent']]);
                if (!($intentrecord = $DB->get_record('paygw_stripe_intents', ['paymentintent' => $session->payment_intent->id]))) {
                    return false;
                }
                $this->save_payment_status($session->id); // Update saved intent status.
                if (!$this->is_paid($session->id)) {
                    // Payment not complete, notify user payment failed.
                    $this->notify_user($intentrecord->userid, 'failed');
                    break;
                }

                // Deliver course.
                $payable = helper::get_payable($metadata['component'], $metadata['paymentarea'], $metadata['itemid']);
                $cost = helper::get_rounded_cost($payable->get_amount(), $payable->get_currency(),
                    helper::get_gateway_surcharge('stripe'));
                $paymentid = helper::save_payment($payable->get_account_id(), $metadata['component'], $metadata['paymentarea'],
                    $metadata['itemid'], $intentrecord->userid, $cost, $payable->get_currency(), 'stripe');
                helper::deliver_order($metadata['component'], $metadata['paymentarea'], $metadata['itemid'], $paymentid,
                    $intentrecord->userid);

                // Notify user payment was successful.
                $url = helper::get_success_url($metadata['component'], $metadata['paymentarea'], $metadata['itemid']);
                $this->notify_user($intentrecord->userid, 'successful', ['url' => $url->out()]);
                break;
            case 'checkout.session.async_payment_failed':
                // Events are sent to all subscribed webhooks, verify we are the correct receipt for this event.
                $session = $this->stripe->checkout->sessions->retrieve($event->data->object->id, ['expand' => ['payment_intent']]);
                if (!($intentrecord = $DB->get_record('paygw_stripe_intents', ['paymentintent' => $session->payment_intent->id]))) {
                    return false;
                }
                $this->save_payment_status($session->id); // Update saved intent status.
                // Notify user payment failed.
                $this->notify_user($intentrecord->userid, 'failed');
                break;
            // Handle customer subscriptions being deleted.
            case 'customer.subscription.deleted':
                if (!($moodlesub = $DB->get_record('paygw_stripe_subscriptions', ['subscriptionid' => $event->data->object->id]))) {
                    return false;
                }
                $this->cancel_subscription($moodlesub, false);
                break;
            case 'customer.subscription.updated':
                if (!($moodlesub = $DB->get_record('paygw_stripe_subscriptions', ['subscriptionid' => $event->data->object->id]))) {
                    return false;
                }
                $subscription = $this->stripe->subscriptions->retrieve($moodlesub->subscriptionid);
                $moodlesub->status = $subscription->status;
                $DB->update_record('paygw_stripe_subscriptions', $moodlesub);
                break;
            default:
                return false;
        }
        return true;
    }

    /**
     * Send message to user regarding payment status.
     *
     * @param int $userto User ID to send notification to
     * @param string $status Payment status
     * @param array $data Data passed to get_string
     * @return void
     * @throws \coding_exception
     */
    private function notify_user(int $userto, string $status, array $data = []) {
        $eventdata = new \core\message\message();
        $eventdata->courseid = SITEID;
        $eventdata->component = 'paygw_stripe';
        $eventdata->name = 'payment_' . $status;
        $eventdata->notification = 1;
        $eventdata->userfrom = core_user::get_noreply_user();
        $eventdata->userto = $userto;
        $eventdata->subject = get_string('payment:' . $status . ':subject', 'paygw_stripe', $data);
        $eventdata->fullmessage = get_string('payment:' . $status . ':message', 'paygw_stripe', $data);
        $eventdata->fullmessageformat = FORMAT_PLAIN;
        $eventdata->fullmessagehtml = '';
        $eventdata->smallmessage = '';
        if (isset($data['url'])) {
            $eventdata->contexturl = $data['url'];
        }
        message_send($eventdata);
    }

    /**
     * Get data table data for a specific subscription.
     *
     * @param \stdClass $moodlesub Moodle subscription record
     * @return array Table data
     * @throws ApiErrorException
     * @throws \coding_exception
     * @throws \moodle_exception
     */
    public function get_subscription_table_data(\stdClass $moodlesub): ?array {
        $product = $this->stripe->products->retrieve($moodlesub->productid);
        $price = $this->stripe->prices->retrieve($moodlesub->priceid);
        try {
            $subscription = $this->stripe->subscriptions->retrieve($moodlesub->subscriptionid, ['expand' => ['schedule']]);

            $cancellink =
                new moodle_url('/payment/gateway/stripe/cancel.php', ['subscriptionid' => $moodlesub->id]);
            $portallink =
                new moodle_url('/payment/gateway/stripe/subscriptions.php',
                    ['action' => 'portal', 'subscriptionid' => $moodlesub->id]);

            return [
                $product->name,
                $this->get_localised_cost($price->unit_amount, $price->currency) . ' / ' .
                get_string('customsubscriptioninterval:' . $price->recurring->interval, 'paygw_stripe'),
                userdate($subscription->current_period_end),
                get_string('subscriptionstatus:' . $moodlesub->status, 'paygw_stripe'),
                $moodlesub->status != 'canceled' ?
                    \html_writer::link($portallink, get_string('updatepaymentmethod', 'paygw_stripe')) : '',
                $moodlesub->status != 'canceled' ? \html_writer::link($cancellink, get_string('cancel', 'paygw_stripe')) : '',
            ];
        } catch (ApiErrorException $err) {
            return null;
        }
    }

    /**
     * Cancel a given subscription.
     * Unenrol the user from a course if that was the product chosen.
     *
     * @param \stdClass $moodlesub Moodle subscription record
     * @param bool $cancelstripe Attempt to cancel subscription within Stripe
     * @return void
     * @throws ApiErrorException
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function cancel_subscription(\stdClass $moodlesub, bool $cancelstripe = true) {
        global $DB;

        if ($cancelstripe) {
            $subscription = $this->stripe->subscriptions->cancel($moodlesub->subscriptionid);
        } else {
            $subscription = $this->stripe->subscriptions->retrieve($moodlesub->subscriptionid);
        }
        $datum = $DB->get_record('paygw_stripe_subscriptions', ['subscriptionid' => $moodlesub->subscriptionid]);
        $datum->status = $subscription->status;
        $DB->update_record('paygw_stripe_subscriptions', $datum);

        $product = $DB->get_record('paygw_stripe_products', ['productid' => $moodlesub->productid]);
        if ($product->component == 'enrol_fee') {
            // A course was the product. Let's unenrol the user.
            $instance = $DB->get_record('enrol', ['enrol' => 'fee', 'id' => $product->itemid], '*', MUST_EXIST);
            $plugin = enrol_get_plugin('fee');
            $plugin->unenrol_user($instance, $moodlesub->userid);
        }
    }

    /**
     * Redirects user to the Stripe subscription management portal.
     *
     * @param \stdClass $moodlesub Moodle subscription record
     * @return void
     * @throws ApiErrorException
     */
    public function load_portal(\stdClass $moodlesub) {
        $subscription = $this->stripe->subscriptions->retrieve($moodlesub->subscriptionid);
        $customer = $this->stripe->customers->retrieve($subscription->customer);

        $returnurl = new moodle_url('/payment/gateway/stripe/subscriptions.php');
        $session = $this->stripe->billingPortal->sessions->create([
            'customer' => $customer,
            'flow_data' => [
                'type' => 'payment_method_update',
                'after_completion' => [
                    'type' => 'redirect',
                    'redirect' => [
                        'return_url' => $returnurl->out()
                    ]
                ],
            ],
            'return_url' => $returnurl->out(),
        ]);

        header("HTTP/1.1 303 See Other");
        header("Location: " . $session->url);
    }

    /**
     * Turns the subscriptioninterval config setting into the data required for
     * creating a price.
     *
     * @param \stdClass $config
     * @return array
     */
    private function get_subscription_config_price_details($config): array {
        switch ($config->subscriptioninterval) {
            case 'daily':
                return [
                    'interval' => 'day',
                    'interval_count' => 1,
                ];
            case 'weekly':
                return [
                    'interval' => 'week',
                    'interval_count' => 1,
                ];
            case 'monthly':
                return [
                    'interval' => 'month',
                    'interval_count' => 1,
                ];
            case 'every3months':
                return [
                    'interval' => 'month',
                    'interval_count' => 3,
                ];
            case 'every6months':
                return [
                    'interval' => 'month',
                    'interval_count' => 6,
                ];
            case 'yearly':
                return [
                    'interval' => 'year',
                    'interval_count' => 1,
                ];
            case 'custom':
                return [
                    'interval' => $config->customsubscriptioninterval,
                    'interval_count' => $config->customsubscriptionintervalcount,
                ];
            default:
                return [
                    'interval' => 'month',
                    'interval_count' => 1
                ];
        }
    }

    /**
     * Retrieve start and end dates for anchored billing, based on config subscription settings.
     *
     * @param \stdClass $config
     * @return array
     * @throws \Exception
     */
    private function get_anchor_billing_dates($config): array {
        $dates = [
            'daily' => [
                new DateTime('this day 00:00:00', new DateTimeZone('UTC')),
                new DateTime('next day 00:00:00', new DateTimeZone('UTC')),
            ],
            'weekly' => [
                new DateTime('first day of this week 00:00:00', new DateTimeZone('UTC')),
                new DateTime('first day of next week 00:00:00', new DateTimeZone('UTC')),
            ],
            'monthly' => [
                new DateTime('first day of this month 00:00:00', new DateTimeZone('UTC')),
                new DateTime('first day of next month 00:00:00', new DateTimeZone('UTC')),
            ],
            'every3months' => [
                new DateTime('first day of this month 00:00:00', new DateTimeZone('UTC')),
                (new DateTime('first day of this month 00:00:00',
                    new DateTimeZone('UTC')))->add(DateInterval::createFromDateString('3 months'))
            ],
            'every6months' => [
                new DateTime('first day of this month 00:00:00', new DateTimeZone('UTC')),
                (new DateTime('first day of this month 00:00:00',
                    new DateTimeZone('UTC')))->add(DateInterval::createFromDateString('6 months'))
            ],
            'yearly' => [
                new DateTime('first day of this year 00:00:00', new DateTimeZone('UTC')),
                new DateTime('first day of next year 00:00:00', new DateTimeZone('UTC'))
            ]
        ];
        if ($config->subscriptioninterval !== 'custom') {
            return $dates[$config->subscriptioninterval];
        }
        if ($config->customsubscriptioninterval === 'day') {
            return [
                new DateTime('this day 00:00:00', new DateTimeZone('UTC')),
                (new DateTime('this day 00:00:00',
                    new DateTimeZone('UTC')))->add(DateInterval::createFromDateString($config->customsubscriptionintervalcount .
                    ' days'))
            ];
        } else {
            return [
                new DateTime('first day of this ' . $config->customsubscriptioninterval . ' 00:00:00', new DateTimeZone('UTC')),
                (new DateTime('first day of this ' . $config->customsubscriptioninterval . ' 00:00:00',
                    new DateTimeZone('UTC')))->add(DateInterval::createFromDateString($config->customsubscriptionintervalcount .
                    ' ' . $config->customsubscriptioninterval . 's'))
            ];
        }
    }

    /**
     * Retrieve the end date of a trial period.
     *
     * @param \stdClass $config
     * @return DateTime
     * @throws \Exception
     */
    private function get_trial_end_date($config): DateTime {
        if ($config->firstintervalfree) {
            list ($date, $nextdate) = $this->get_anchor_billing_dates($config);
            return $nextdate;
        }
        $dates = [
            'daily' => [
                new DateTime('next day 00:00:00', new DateTimeZone('UTC')),
            ],
            'weekly' => [
                new DateTime('this day next week 00:00:00', new DateTimeZone('UTC')),
            ],
            'monthly' => [
                new DateTime('this day next month 00:00:00', new DateTimeZone('UTC')),
            ],
            'every3months' => [
                (new DateTime('this day next month 00:00:00',
                    new DateTimeZone('UTC')))->add(DateInterval::createFromDateString('3 months'))
            ],
            'every6months' => [
                (new DateTime('this day next month 00:00:00',
                    new DateTimeZone('UTC')))->add(DateInterval::createFromDateString('6 months'))
            ],
            'yearly' => [
                new DateTime('this day next year 00:00:00', new DateTimeZone('UTC'))
            ]
        ];
        if ($config->subscriptioninterval !== 'custom') {
            return $dates[$config->subscriptioninterval];
        }
        return (new DateTime('today 00:00:00',
            new DateTimeZone('UTC')))->add(DateInterval::createFromDateString($config->customsubscriptionintervalcount .
            ' ' . $config->customsubscriptioninterval . 's'));
    }

}
