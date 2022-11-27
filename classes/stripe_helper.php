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

use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Product;
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
            '1.15',
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
     * @return Price|null
     */
    public function get_price(Product $product): ?Price {
        try {
            $prices = $this->stripe->prices->all(['product' => $product->id]);
            foreach ($prices as $price) {
                if ($price instanceof Price) {
                    if ($price->active) {
                        return $price;
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
     */
    public function create_price(string $currency, string $productid, float $unitamount, bool $automatictax,
        ?string $defaultbehavior) {
        $pricedata = [
            'currency' => $currency,
            'product' => $productid,
            'unit_amount' => $unitamount,
        ];
        if ($automatictax == 1) {
            $pricedata['tax_behavior'] = $defaultbehavior ?? 'inclusive';
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
     * Create a payment intent and return with the checkout session id.
     *
     * @param object $config
     * @param string $currency
     * @param string $description
     * @param float $cost
     * @param string $component
     * @param string $paymentarea
     * @param string $itemid
     * @return string
     * @throws ApiErrorException
     */
    public function generate_payment(object $config, string $currency, string $description, float $cost, string $component,
        string $paymentarea, string $itemid): string {
        global $CFG, $USER;

        $unitamount = $this->get_unit_amount($cost, $currency);
        $currency = strtolower($currency);

        if (!$product = $this->get_product($component, $paymentarea, $itemid)) {
            $product = $this->create_product($description, $component, $paymentarea, $itemid);
        }
        if (!$price = $this->get_price($product)) {
            $price = $this->create_price($currency, $product->id, $unitamount, $config->enableautomatictax == 1,
                $config->defaulttaxbehavior);
        } else {
            if ($price->unit_amount != $unitamount || $price->currency != $currency) {
                // We cannot update the price or currency, so we must create a new price.
                $price->updateAttributes(['active' => false]);
                $price->save();
                $price = $this->create_price($currency, $product->id, $unitamount, $config->enableautomatictax == 1,
                    $config->defaulttaxbehavior);
            }
            // Set tax behavior if not set already.
            if ($config->enableautomatictax == 1 && (!isset($price->tax_behavior) || $price->tax_behavior === 'unspecified')) {
                $price->updateAttributes(['tax_behavior' => $config->tax_behavior ?? 'inclusive']);
                $price->save();
            }
        }

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
            ],
            'allow_promotion_codes' => $config->allowpromotioncodes == 1,
            'customer_update' => [
                'address' => 'auto',
            ],
        ]);

        return $session->id;
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
     * Convert the cost into the unit amount accounting for zero-decimal currencies.
     *
     * @param float $cost
     * @param string $currency
     * @return float
     */
    public function get_unit_amount(float $cost, string $currency): float {
        if (in_array($currency, gateway::get_zero_decimal_currencies())) {
            return $cost;
        }
        return $cost * 100;
    }

    /**
     * Saves the payment intent status with customer and product id details.
     *
     * @param string $sessionid
     * @return void
     * @throws ApiErrorException|\dml_exception
     */
    public function save_payment_status(string $sessionid) {
        global $DB, $USER;

        $session = $this->stripe->checkout->sessions->retrieve($sessionid, ['expand' => ['line_items', 'customer']]);

        $intent = $DB->get_record('paygw_stripe_intents', ['paymentintent' => $session->payment_intent]);
        if ($intent != null) {
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

}
