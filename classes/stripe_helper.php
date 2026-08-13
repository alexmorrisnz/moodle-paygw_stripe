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
use paygw_stripe\local\service\customer_service;
use paygw_stripe\local\service\locale_service;
use paygw_stripe\local\service\product_pricing_service;
use paygw_stripe\local\service\stripe_service_factory;
use paygw_stripe\local\service\subscription_service;
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
    private subscription_service $subscriptionservice;

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
        $this->subscriptionservice = $factory->subscription_service();
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
            $this->subscriptionservice->save_subscription($session);
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
}
