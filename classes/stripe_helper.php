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
use paygw_stripe\local\service\customer_service;
use paygw_stripe\local\service\locale_service;
use paygw_stripe\local\service\product_pricing_service;
use paygw_stripe\local\service\stripe_service_factory;
use paygw_stripe\local\service\webhook_service;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;
use Stripe\StripeClient;

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
     * @var StripeClient The Stripe API client.
     */
    private $stripe;
    /**
     * @var string Public API key.
     */
    private $apikey;

    /**
     * @var string Stripe API version set explicitly in Stripe client.
     */
    public static $apiversion = '2025-06-30.basil';

    private product_pricing_service $productpricingservice;
    private webhook_service $webhookservice;
    private customer_service $customerservice;
    private locale_service $localeservice;

    /**
     * Initialise the Stripe API client.
     *
     * @param string $apikey
     * @param string $secretkey
     */
    public function __construct(
        string $apikey,
        string $secretkey,
        ?stripe_service_factory $factory = null
    ) {
        $this->apikey = $apikey;
        $this->stripe = new StripeClient([
            'api_key' => $secretkey,
            'stripe_version' => self::$apiversion,
        ]);
        Stripe::setAppInfo(
            'Moodle Stripe Payment Gateway',
            get_config('paygw_stripe')->version,
            'https://github.com/alexmorrisnz/moodle-paygw_stripe'
        );

        if ($factory === null) {
            $factory = new stripe_service_factory($apikey, $secretkey);
        }

        $this->productpricingservice = $factory->product_pricing_service();
        $this->webhookservice = $factory->webhook_service();
        $this->customerservice = $factory->customer_service();
        $this->localeservice = new locale_service();
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
    public function generate_payment(
        object $config,
        payable $payable,
        string $description,
        float $cost,
        string $component,
        string $paymentarea,
        string $itemid
    ): string {
        global $CFG, $USER;

        // Ensure webhook exists before we potentially use it.
        $this->webhookservice->create_webhook($payable->get_account_id());

        [$product, $price] = $this->productpricingservice->create_product_and_price(
            $config,
            $payable,
            $description,
            $cost,
            $component,
            $paymentarea,
            $itemid
        );

        if (!$customer = $this->customerservice->get_customer($USER->id)) {
            $customer = $this->customerservice->create_customer($USER);
        } else {
            $customer = $this->customerservice->update_customer_details($customer, $USER);
        }

        $stripelocale = $this->localeservice->get_stripe_locale_for_user($USER);

        $session = $this->stripe->checkout->sessions->create([
            'success_url' => $CFG->wwwroot . '/payment/gateway/stripe/process.php?component=' . $component . '&paymentarea=' .
                $paymentarea . '&itemid=' . $itemid . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $CFG->wwwroot . '/payment/gateway/stripe/cancelled.php?component=' . $component . '&paymentarea=' .
                $paymentarea . '&itemid=' . $itemid,
            'locale' => $stripelocale,
            'payment_method_types' => $config->paymentmethods,
            'payment_method_options' => [
                'wechat_pay' => [
                    'client' => "web",
                ],
            ],
            'mode' => 'payment',
            'line_items' => [[
                'price' => $price,
                'quantity' => 1,
            ]],
            'automatic_tax' => [
                'enabled' => $config->enableautomatictax == 1,
            ],
            'billing_address_collection' => ($config->collectbillingaddress ?? 0) == 1 ? 'required' : 'auto',
            'invoice_creation' => [
                'enabled' => ($config->invoicecreation ?? 0) == 1,
                'invoice_data' => [
                    'issuer' => [
                        'type' => 'self',
                    ],
                    'description' => $description,
                ],
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
            'expires_at' => time() + min($CFG->sessiontimeout, 24 * 60 * 60),
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
     * @return string|null
     * @throws ApiErrorException
     * @throws \coding_exception
     * @throws \dml_exception
     * @throws \moodle_exception
     */
    public function generate_subscription(
        object $config,
        payable $payable,
        string $description,
        float $cost,
        string $component,
        string $paymentarea,
        string $itemid
    ): ?string {
        global $CFG, $USER;

        // Ensure webhook exists before we use it.
        $this->webhookservice->create_webhook($payable->get_account_id());

        $pricedetails = $this->get_subscription_config_price_details($config);

        [$product, $price] = $this->productpricingservice->create_product_and_price(
            $config,
            $payable,
            $description,
            $cost,
            $component,
            $paymentarea,
            $itemid,
            $pricedetails
        );

        if (!$customer = $this->customerservice->get_customer($USER->id)) {
            $customer = $this->customerservice->create_customer($USER);
        } else {
            $customer = $this->customerservice->update_customer_details($customer, $USER);
        }

        $stripelocale = $this->localeservice->get_stripe_locale_for_user($USER);

        $subscriptiondata = [
            'metadata' => [
                'userid' => $USER->id,
                'username' => $USER->username,
                'firstname' => $USER->firstname,
                'lastname' => $USER->lastname,
                'component' => $component,
                'paymentarea' => $paymentarea,
                'itemid' => $itemid,
            ],
        ];
        if ($config->anchorbilling) {
            $subscriptiondata['billing_cycle_anchor'] = $this->get_anchor_billing_dates($config)->getTimestamp();
            if ($config->firstintervalfree) {
                $subscriptiondata['proration_behavior'] = 'none';
            }
        }
        if ($config->firstintervalfree && !$config->anchorbilling) {
            $subscriptiondata['trial_end'] = $this->get_trial_end_date($config)->getTimestamp();
        }

        // Create checkout session to set up subscription for customer.
        $session = $this->stripe->checkout->sessions->create([
            'success_url' => $CFG->wwwroot . '/payment/gateway/stripe/process.php?component=' . $component . '&paymentarea=' .
                $paymentarea . '&itemid=' . $itemid . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $CFG->wwwroot . '/payment/gateway/stripe/cancelled.php?component=' . $component . '&paymentarea=' .
                $paymentarea . '&itemid=' . $itemid,
            'locale' => $stripelocale,
            'payment_method_types' => $config->paymentmethods,
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $price,
                'quantity' => 1,
            ]],
            'automatic_tax' => [
                'enabled' => $config->enableautomatictax == 1,
            ],
            'billing_address_collection' => ($config->collectbillingaddress ?? 0) == 1 ? 'required' : 'auto',
            'allow_promotion_codes' => $config->allowpromotioncodes == 1,
            'subscription_data' => $subscriptiondata,
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
            'expires_at' => time() + min($CFG->sessiontimeout, 24 * 60 * 60),
        ]);

        return $session->id;
    }

    /**
     * Retrieve the Checkout Session mode
     *
     * @param string $sessionid Stripe session ID
     * @return string
     * @throws ApiErrorException
     */
    public function get_sessionmode(string $sessionid): string {
        $session = $this->stripe->checkout->sessions->retrieve($sessionid);
        return $session->mode;
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
     * Check the status of a Stripe subscription
     *
     * @param string $sessionid Stripe session ID
     * @return string
     * @throws ApiErrorException
     */
    public function get_subscription_status(string $sessionid): string {
        $session = $this->stripe->checkout->sessions->retrieve($sessionid);
        $subscription = $this->stripe->subscriptions->retrieve($session->subscription);
        return $subscription->status;
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
     * Save checkout session status with customer and product details.
     *
     * @param Session $session
     * @return void
     * @throws \dml_exception
     */
    private function save_checkout_session(Session $session) {
        global $DB, $USER;

        $storedsession = $DB->get_record('paygw_stripe_checkout_sessions', ['checkoutsessionid' => $session->id]);
        if ($storedsession != null) {
            $storedsession->status = $session->status;
            $storedsession->paymentstatus = $session->payment_status;
            $DB->update_record('paygw_stripe_checkout_sessions', $storedsession);
            return;
        }

        $checkoutsession = new \stdClass();
        $checkoutsession->checkoutsessionid = $session->id;
        $checkoutsession->userid = $USER->id;
        $checkoutsession->paymentintent = $session->payment_intent;
        $checkoutsession->customerid = $session->customer->id;
        $checkoutsession->amounttotal = $session->amount_total;
        $checkoutsession->paymentstatus = $session->payment_status;
        $checkoutsession->status = $session->status;
        $checkoutsession->productid = $session->line_items->first()->price->product;

        $DB->insert_record('paygw_stripe_checkout_sessions', $checkoutsession);
    }

    /**
     * Check if a checkout session has been saved in the DB.
     *
     * @param string $sessionid
     * @return bool
     * @throws \dml_exception
     */
    public function is_checkout_session_saved(string $sessionid): bool {
        global $DB;

        if ($DB->get_record('paygw_stripe_checkout_sessions', ['checkoutsessionid' => $sessionid])) {
            return true;
        }
        return false;
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
            $this->save_checkout_session($session);
        }
    }

    /**
     * Deliver course
     *
     * @param string $component
     * @param string $paymentarea
     * @param int $itemid
     * @param int $userid
     * @return void
     */
    public function deliver_course(string $component, string $paymentarea, int $itemid, int $userid) {
        $payable = helper::get_payable($component, $paymentarea, $itemid);
        $cost = helper::get_rounded_cost($payable->get_amount(), $payable->get_currency(), helper::get_gateway_surcharge('stripe'));
        $paymentid = helper::save_payment(
            $payable->get_account_id(),
            $component,
            $paymentarea,
            $itemid,
            $userid,
            $cost,
            $payable->get_currency(),
            'stripe'
        );
        helper::deliver_order($component, $paymentarea, $itemid, $paymentid, $userid);
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
                $session = $this->stripe->checkout->sessions->retrieve($event->data->object->id);
                if (!($sessionrecord = $DB->get_record('paygw_stripe_checkout_sessions', ['checkoutsessionid' => $session->id]))) {
                    return false;
                }

                // Webhook retry already processed this session.
                if ($sessionrecord->paymentstatus === 'paid') {
                    return true;
                }

                $this->save_payment_status($session->id); // Update saved intent status.

                // Deliver course.
                $this->deliver_course(
                    $metadata['component'],
                    $metadata['paymentarea'],
                    $metadata['itemid'],
                    $sessionrecord->userid
                );

                // Notify user payment was successful.
                $url = helper::get_success_url($metadata['component'], $metadata['paymentarea'], $metadata['itemid']);
                $this->notify_user($sessionrecord->userid, 'successful', ['url' => $url->out()]);
                break;
            case 'checkout.session.async_payment_failed':
                // Events are sent to all subscribed webhooks, verify we are the correct receipt for this event.
                $session = $this->stripe->checkout->sessions->retrieve($event->data->object->id);
                if (!($sessionrecord = $DB->get_record('paygw_stripe_checkout_sessions', ['checkoutsessionid' => $session->id]))) {
                    return false;
                }
                $this->save_payment_status($session->id); // Update saved intent status.
                // Notify user payment failed.
                $this->notify_user($sessionrecord->userid, 'failed');
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
                new moodle_url(
                    '/payment/gateway/stripe/subscriptions.php',
                    ['action' => 'portal', 'subscriptionid' => $moodlesub->id]
                );

            return [
                $product->name,
                $this->get_localised_cost($price->unit_amount, $price->currency) . ' / ' .
                get_string('customsubscriptioninterval:' . $price->recurring->interval, 'paygw_stripe'),
                userdate($subscription->items->data[0]->current_period_end),
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
                        'return_url' => $returnurl->out(),
                    ],
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
                    'interval_count' => 1,
                ];
        }
    }

    /**
     * Retrieve start and end dates for anchored billing, based on config subscription settings.
     *
     * @param \stdClass $config
     * @return DateTime
     * @throws \Exception
     */
    private function get_anchor_billing_dates($config): DateTime {
        // Identify first day of the week for weekly intervals.
        $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $calendar = \core_calendar\type_factory::get_calendar_instance();
        $firstdayofweek = $days[$calendar->get_starting_weekday()];

        $dates = [
            'daily' => new DateTime('next day 00:00:00', new DateTimeZone('UTC')),
            'weekly' => new DateTime('this ' . $firstdayofweek . ' 00:00:00', new DateTimeZone('UTC')),
            'monthly' => new DateTime('first day of next month 00:00:00', new DateTimeZone('UTC')),
            'every3months' => (new DateTime(
                'first day of this month 00:00:00',
                new DateTimeZone('UTC')
            ))->add(DateInterval::createFromDateString('3 months')),
            'every6months' => (new DateTime(
                'first day of this month 00:00:00',
                new DateTimeZone('UTC')
            ))->add(DateInterval::createFromDateString('6 months')),
            'yearly' => new DateTime('first day of this year 00:00:00', new DateTimeZone('UTC')),
        ];
        if ($config->subscriptioninterval !== 'custom') {
            return $dates[$config->subscriptioninterval];
        }
        if ($config->customsubscriptioninterval === 'day') {
            return (new DateTime(
                'this day 00:00:00',
                new DateTimeZone('UTC')
            ))->add(DateInterval::createFromDateString($config->customsubscriptionintervalcount .
                ' days'));
        } else {
            return (new DateTime(
                'first day of this ' . $config->customsubscriptioninterval . ' 00:00:00',
                new DateTimeZone('UTC')
            ))->add(DateInterval::createFromDateString($config->customsubscriptionintervalcount .
                ' ' . $config->customsubscriptioninterval . 's'));
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
        $dates = [
            'daily' => new DateTime('next day 00:00:00', new DateTimeZone('UTC')),
            'weekly' => new DateTime('this day next week 00:00:00', new DateTimeZone('UTC')),
            'monthly' => new DateTime('this day next month 00:00:00', new DateTimeZone('UTC')),
            'every3months' => (new DateTime(
                'this day next month 00:00:00',
                new DateTimeZone('UTC')
            ))->add(DateInterval::createFromDateString('3 months')),
            'every6months' => (new DateTime(
                'this day next month 00:00:00',
                new DateTimeZone('UTC')
            ))->add(DateInterval::createFromDateString('6 months')),
            'yearly' => new DateTime('this day next year 00:00:00', new DateTimeZone('UTC')),
        ];
        if ($config->subscriptioninterval !== 'custom') {
            return $dates[$config->subscriptioninterval];
        }
        return (new DateTime(
            'today 00:00:00',
            new DateTimeZone('UTC')
        ))->add(DateInterval::createFromDateString($config->customsubscriptionintervalcount .
            ' ' . $config->customsubscriptioninterval . 's'));
    }
}
