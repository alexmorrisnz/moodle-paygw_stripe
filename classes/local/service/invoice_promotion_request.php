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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace paygw_stripe\local\service;

use core_payment\helper;
use core_payment\local\entities\payable;
use paygw_stripe\gateway;

/**
 * Purchase checks and discount selections for the invoice payment page.
 *
 * @package paygw_stripe
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class invoice_promotion_request {
    /**
     * Resolve enabled gateway configuration and price only from Moodle.
     *
     * @param string $component Payment component.
     * @param string $paymentarea Payment area.
     * @param int $itemid Purchase item.
     * @return array Config, payable, original rounded cost and bound identity.
     */
    public static function purchase(string $component, string $paymentarea, int $itemid): array {
        global $DB;
        require_login(null, false);
        if ($component === 'enrol_fee' && $paymentarea === 'fee') {
            $instance = $DB->get_record('enrol', ['id' => $itemid, 'enrol' => 'fee', 'status' => ENROL_INSTANCE_ENABLED],
                '*', MUST_EXIST);
            $course = get_course($instance->courseid);
            $context = \context_course::instance($course->id);
            if (!$course->visible && !has_capability('moodle/course:viewhiddencourses', $context)) {
                throw new \moodle_exception('invoiceunavailable', 'paygw_stripe');
            }
            if (($instance->enrolstartdate && $instance->enrolstartdate > time()) ||
                ($instance->enrolenddate && $instance->enrolenddate < time())) {
                throw new \moodle_exception('invoiceunavailable', 'paygw_stripe');
            }
        }
        if (!in_array('stripe', helper::get_available_gateways($component, $paymentarea, $itemid), true)) {
            throw new \moodle_exception('invoiceunavailable', 'paygw_stripe');
        }
        $config = (object)helper::get_gateway_configuration($component, $paymentarea, $itemid, 'stripe');
        $payable = helper::get_payable($component, $paymentarea, $itemid);
        $cost = helper::get_rounded_cost($payable->get_amount(), $payable->get_currency(), helper::get_gateway_surcharge('stripe'));
        $identity = self::identity($component, $paymentarea, $itemid, $payable, $cost);
        return [$config, $payable, $cost, $identity];
    }

    /**
     * Build a consistently typed identity, including the original price and payment account.
     *
     * @param string $component Component.
     * @param string $paymentarea Area.
     * @param int $itemid Item.
     * @param payable $payable Server-derived payable.
     * @param float $cost Rounded cost.
     * @return array Identity.
     */
    public static function identity(string $component, string $paymentarea, int $itemid, payable $payable, float $cost): array {
        $currency = strtoupper($payable->get_currency());
        $factor = in_array($currency, gateway::get_zero_decimal_currencies(), true) ? 1 : 100;
        return [
            'component' => $component, 'paymentarea' => $paymentarea, 'itemid' => $itemid,
            'paymentaccountid' => (int)$payable->get_account_id(),
            'amount' => (int)round($cost * $factor), 'currency' => strtolower($currency),
        ];
    }

    /**
     * Return form options and, on explicit apply, a bound selection token.
     *
     * @param string $component Component.
     * @param string $paymentarea Area.
     * @param int $itemid Item.
     * @param string $code Entered code, empty to explicitly continue without a discount.
     * @param bool $apply Whether to validate and store a choice.
     * @return array{enabled: bool, resume: bool, token: string, code: string, message: string}
     */
    public static function options(string $component, string $paymentarea, int $itemid, string $code, bool $apply): array {
        global $SESSION, $USER;
        [$config, $payable, $cost, $identity] = self::purchase($component, $paymentarea, $itemid);
        $enabled = ($config->type ?? '') === 'invoice' && !empty($config->invoicepromotioncodes);
        $result = ['enabled' => $enabled, 'resume' => false, 'token' => '', 'code' => '', 'message' => ''];
        if (!$enabled) {
            return $result;
        }
        $code = trim($code);
        $existing = (new \paygw_stripe\local\repository\invoice_repository())->find_active_purchase(
            (int)$USER->id, $identity['paymentaccountid'], $component, $paymentarea, $itemid,
            $identity['amount'], $identity['currency']
        );
        if ($existing && $existing->status !== 'billing') {
            $result['resume'] = true;
            $result['message'] = get_string('invoicepromotionresume', 'paygw_stripe');
            if (!$apply) {
                return $result;
            }
            if ($code !== '') {
                throw new \moodle_exception('invoicepromotionlocked', 'paygw_stripe');
            }
            // An existing invoice keeps its original discount even after the code or old choice expires.
            $result['token'] = invoice_promotion_choices::save($identity, $existing->promotioncodeid);
            return $result;
        }
        if (!$apply) {
            return $result;
        }
        $promotionid = null;
        $amount = $identity['amount'];
        if ($code !== '') {
            $attempts = (array)($SESSION->paygw_stripe_promotion_attempts ?? []);
            $attempts = array_values(array_filter($attempts, static fn($time) => $time > time() - MINSECS));
            if (count($attempts) >= 20) {
                throw new \moodle_exception('invoicepromotionratelimit', 'paygw_stripe');
            }
            $attempts[] = time();
            $SESSION->paygw_stripe_promotion_attempts = $attempts;
            $factory = new stripe_service_factory($config->apikey, $config->secretkey);
            $customers = $factory->customer_service();
            $customer = null;
            try {
                $customer = $customers->get_invoice_customer($USER);
                $pricing = $factory->product_pricing_service();
                $product = $pricing->get_product($component, $paymentarea, (string)$itemid) ??
                    $pricing->create_product(purchase_description::get($component, $paymentarea, $itemid),
                        $component, $paymentarea, (string)$itemid);
                $quote = $factory->invoice_promotion_service()->quote($code, $amount, $identity['currency'],
                    $customer->id, $product->id);
                $promotionid = $quote['promotioncodeid'];
                $amount = $quote['amount'];
                $result['code'] = $quote['code'];
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                throw new \moodle_exception('invoicepromotioninvalid', 'paygw_stripe', '', null,
                    'Stripe request: ' . $e->getRequestId() . '; code: ' . $e->getStripeCode());
            } catch (\Stripe\Exception\ApiErrorException $e) {
                throw new \moodle_exception('invoicepromotionunavailable', 'paygw_stripe', '', null,
                    'Stripe request: ' . $e->getRequestId() . '; code: ' . $e->getStripeCode());
            } finally {
                if ($customer) {
                    $customers->release_invoice_identity_if_unused((int)$USER->id, $customer->id);
                }
            }
        }
        $factor = in_array(strtoupper($identity['currency']), gateway::get_zero_decimal_currencies(), true) ? 1 : 100;
        $result['message'] = get_string($promotionid ? 'invoicepromotionestimate' : 'invoicepromotionwithout', 'paygw_stripe',
            helper::get_cost_as_string($amount / $factor, $payable->get_currency()));
        $result['token'] = invoice_promotion_choices::save($identity, $promotionid);
        return $result;
    }
}
