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
use core_user;
use paygw_stripe\local\service\checkout_service;
use paygw_stripe\local\service\customer_service;
use paygw_stripe\local\service\locale_service;
use paygw_stripe\local\service\product_pricing_service;
use paygw_stripe\local\service\stripe_service_factory;
use paygw_stripe\local\service\subscription_service;
use paygw_stripe\local\service\webhook_service;
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

    /**
     * @var product_pricing_service Service for managing Stripe products and prices.
     */
    private product_pricing_service $productpricingservice;
    /**
     * @var webhook_service Service for managing Stripe webhooks.
     */
    private webhook_service $webhookservice;
    /**
     * @var customer_service Service for managing Stripe customers.
     */
    private customer_service $customerservice;
    /**
     * @var locale_service Service for resolving locale and currency information.
     */
    private locale_service $localeservice;
    /**
     * @var subscription_service Service for managing Stripe subscriptions.
     */
    private subscription_service $subscriptionservice;
    /**
     * @var checkout_service Service for managing Stripe checkout sessions.
     */
    private checkout_service $checkoutservice;

    /**
     * Initialise the Stripe API client.
     *
     * @param string $apikey
     * @param string $secretkey
     * @param stripe_service_factory|null $factory
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
        $this->checkoutservice = $factory->checkout_service();
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
        if (!isset($event->data->object)) {
            return false;
        }

        switch ($event->type) {
            // Process an async payment event.
            // Deliver the course if payment was successful or notify the user the payment failed.
            case 'checkout.session.async_payment_succeeded':
                // Events are sent to all subscribed webhooks, verify we are the correct receipt for this event.
                $session = $this->stripe->checkout->sessions->retrieve($event->data->object->id);
                if (!($sessionrecord = $this->checkoutservice->find_session($session->id))) {
                    return false;
                }

                // Webhook retry already processed this session.
                if ($sessionrecord->paymentstatus === 'paid') {
                    return true;
                }

                $this->checkoutservice->save_payment_status($session->id); // Update saved intent status.

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
                if (!($sessionrecord = $this->checkoutservice->find_session($session->id))) {
                    return false;
                }
                $this->checkoutservice->save_payment_status($session->id); // Update saved intent status.
                // Notify user payment failed.
                $this->notify_user($sessionrecord->userid, 'failed');
                break;
            // Handle customer subscriptions being deleted.
            case 'customer.subscription.deleted':
                if (!($moodlesub = $this->subscriptionservice->find_subscription($event->data->object->id))) {
                    return false;
                }
                $this->subscriptionservice->cancel_subscription($moodlesub, false);
                break;
            case 'customer.subscription.updated':
                if (!($moodlesub = $this->subscriptionservice->find_subscription($event->data->object->id))) {
                    return false;
                }
                $this->subscriptionservice->sync_status($moodlesub);
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
