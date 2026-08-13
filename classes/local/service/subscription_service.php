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

use core_payment\local\entities\payable;
use DateInterval;
use DateTime;
use DateTimeZone;
use moodle_url;
use paygw_stripe\gateway;
use paygw_stripe\local\model\subscription;
use paygw_stripe\local\repository\product_repository;
use paygw_stripe\local\repository\subscription_repository;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Subscription service.
 *
 * @copyright 2026 Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subscription_service {
    /**
     * @var StripeClient The Stripe API client.
     */
    private $stripe;

    /**
     * @var subscription_repository The subscription repository.
     */
    private subscription_repository $subscriptionrepository;
    /**
     * @var product_repository The product repository.
     */
    private product_repository $productrepository;
    /**
     * @var product_pricing_service The product pricing service.
     */
    private product_pricing_service $productpricingservice;
    /**
     * @var customer_service The customer service.
     */
    private customer_service $customerservice;
    /**
     * @var locale_service The locale service.
     */
    private locale_service $localeservice;
    /**
     * @var webhook_service The webhook service.
     */
    private webhook_service $webhookservice;

    /**
     * Subscription service constructor.
     *
     * @param StripeClient $stripe The Stripe API client.
     */
    public function __construct(StripeClient $stripe) {
        $this->stripe = $stripe;

        $this->subscriptionrepository = new subscription_repository();
        $this->productrepository = new product_repository();

        $this->productpricingservice = new product_pricing_service($stripe);
        $this->customerservice = new customer_service($stripe);
        $this->localeservice = new locale_service();
        $this->webhookservice = new webhook_service($stripe);
    }

    /**
     * Retrieve Stipe subscription details and save in Moodle based on Stripe checkout session.
     *
     * @param Session $session
     * @return void
     * @throws \dml_exception
     */
    public function save_subscription(Session $session) {
        global $USER;

        $subscription = $this->stripe->subscriptions->retrieve($session->subscription);

        $msub = $this->subscriptionrepository->find_by_subscriptionid($session->subscription);
        if ($msub != null) {
            $msub = $msub->with_status($subscription->status);
            $this->subscriptionrepository->save($msub);
            return;
        }

        $record = new subscription(
            null,
            (int)$USER->id,
            $session->subscription,
            $session->customer->id,
            $subscription->status,
            $session->line_items->first()->price->product,
            $session->line_items->first()->price->id
        );
        $this->subscriptionrepository->save($record);
    }

    /**
     * Find a stored subscription by Stripe subscription id.
     *
     * @param string $subscriptionid
     * @return subscription|null
     * @throws \dml_exception
     */
    public function find_subscription(string $subscriptionid): ?subscription {
        return $this->subscriptionrepository->find_by_subscriptionid($subscriptionid);
    }

    /**
     * Refresh the stored status for a subscription from Stripe.
     *
     * @param subscription $moodlesub
     * @return void
     * @throws ApiErrorException|\dml_exception
     */
    public function sync_status(subscription $moodlesub): void {
        $subscription = $this->stripe->subscriptions->retrieve($moodlesub->subscriptionid);
        $this->subscriptionrepository->save($moodlesub->with_status($subscription->status));
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

        if (!$customer = $this->customerservice->get_customer((int)$USER->id)) {
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
     * Redirects user to the Stripe subscription management portal.
     *
     * @param subscription $moodlesub Moodle subscription record
     * @return void
     * @throws ApiErrorException
     */
    public function load_portal(subscription $moodlesub) {
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

        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            return;
        }
        header("HTTP/1.1 303 See Other");
        header("Location: " . $session->url);
    }

    /**
     * Cancel a given subscription.
     * Unenrol the user from a course if that was the product chosen.
     *
     * @param subscription $moodlesub Moodle subscription record
     * @param bool $cancelstripe Attempt to cancel subscription within Stripe
     * @return void
     * @throws ApiErrorException
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function cancel_subscription(subscription $moodlesub, bool $cancelstripe = true) {
        global $DB;

        if ($cancelstripe) {
            $subscription = $this->stripe->subscriptions->cancel($moodlesub->subscriptionid);
        } else {
            $subscription = $this->stripe->subscriptions->retrieve($moodlesub->subscriptionid);
        }

        $msub = $this->subscriptionrepository->find_by_subscriptionid($moodlesub->subscriptionid);
        $msub = $msub->with_status($subscription->status);
        $this->subscriptionrepository->save($msub);

        $product = $this->productrepository->find_by_productid($moodlesub->productid);
        if ($product->component == 'enrol_fee') {
            // A course was the product. Let's unenrol the user.
            $instance = $DB->get_record('enrol', ['enrol' => 'fee', 'id' => $product->itemid], '*', MUST_EXIST);
            $plugin = enrol_get_plugin('fee');
            $plugin->unenrol_user($instance, $moodlesub->userid);
        }
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
