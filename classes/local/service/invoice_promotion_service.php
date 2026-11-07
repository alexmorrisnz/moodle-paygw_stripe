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

use Stripe\PromotionCode;
use Stripe\StripeClient;

/**
 * Check user-entered promotion codes without redeeming them or creating an invoice.
 *
 * Stripe remains authoritative when the actual invoice is created. Quotes are estimates
 * before the billing identity, final taxes and Stripe rounding have been established.
 *
 * @package paygw_stripe
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class invoice_promotion_service {
    /** @var StripeClient Stripe API client. */
    private StripeClient $stripe;

    /**
     * Create the promotion validation service.
     *
     * @param StripeClient $stripe Stripe API client.
     */
    public function __construct(StripeClient $stripe) {
        $this->stripe = $stripe;
    }

    /**
     * Find an eligible customer-facing code; never accept a coupon ID from the browser.
     *
     * @param string $code Customer-facing code.
     * @param int $amount Original amount in minor units.
     * @param string $currency Currency.
     * @param string $customerid Bound Stripe customer.
     * @param string $productid Bound Stripe product.
     * @return array Validated ID, canonical code and estimated discounted amount.
     */
    public function quote(string $code, int $amount, string $currency, string $customerid, string $productid): array {
        $code = trim($code);
        if ($code === '' || strlen($code) > 200) {
            throw new \moodle_exception('invoicepromotioninvalid', 'paygw_stripe');
        }
        $codes = $this->stripe->promotionCodes->all(['code' => $code, 'active' => true, 'limit' => 100]);
        foreach ($codes->autoPagingIterator() as $promotion) {
            if (
                strcasecmp((string)$promotion->code, $code) === 0 &&
                (empty($promotion->customer) || $promotion->customer === $customerid) &&
                empty($promotion->customer_account)
            ) {
                return $this->check($promotion, $amount, $currency, $customerid, $productid);
            }
        }
        throw new \moodle_exception('invoicepromotioninvalid', 'paygw_stripe');
    }

    /**
     * Recheck a previously bound ID before starting a new billing request.
     *
     * @param string $id Promotion code ID obtained server-side.
     * @param int $amount Original amount in minor units.
     * @param string $currency Currency.
     * @param string $customerid Bound customer.
     * @param string $productid Bound product.
     * @return array Validated quote.
     */
    public function quote_by_id(string $id, int $amount, string $currency, string $customerid, string $productid): array {
        return $this->check($this->stripe->promotionCodes->retrieve($id), $amount, $currency, $customerid, $productid);
    }

    /**
     * Check restrictions and calculate an informational estimate in integer minor units.
     *
     * @param PromotionCode $promotion Promotion code.
     * @param int $amount Original amount.
     * @param string $currency Currency.
     * @param string $customerid Customer.
     * @param string $productid Product.
     * @return array Validated quote.
     */
    private function check(PromotionCode $promotion, int $amount, string $currency, string $customerid, string $productid): array {
        $currency = strtolower($currency);
        $invalid = !$promotion->active || $amount < 0 ||
            (!empty($promotion->expires_at) && $promotion->expires_at <= time()) ||
            (!empty($promotion->max_redemptions) && $promotion->times_redeemed >= $promotion->max_redemptions) ||
            (!empty($promotion->customer) && $promotion->customer !== $customerid) || !empty($promotion->customer_account);
        if ($invalid) {
            throw new \moodle_exception('invoicepromotioninvalid', 'paygw_stripe');
        }
        // The pinned Stripe API exposes promotion.coupon; retain compatibility with older objects.
        $couponid = $promotion->promotion->coupon ?? $promotion->coupon ?? null;
        if (is_object($couponid)) {
            $couponid = $couponid->id;
        }
        if (!$couponid || (($promotion->promotion->type ?? 'coupon') !== 'coupon')) {
            throw new \moodle_exception('invoicepromotioninvalid', 'paygw_stripe');
        }
        $coupon = $this->stripe->coupons->retrieve((string)$couponid, ['expand' => ['currency_options']]);
        if (
            !empty($coupon->deleted) || !$coupon->valid ||
            (!empty($coupon->redeem_by) && $coupon->redeem_by <= time()) ||
            (!empty($coupon->max_redemptions) && $coupon->times_redeemed >= $coupon->max_redemptions) ||
            (!empty($coupon->applies_to->products) && !in_array($productid, $coupon->applies_to->products, true))
        ) {
            throw new \moodle_exception('invoicepromotioninvalid', 'paygw_stripe');
        }
        $restrictions = $promotion->restrictions;
        $minimum = $restrictions->currency_options->{$currency}->minimum_amount ?? null;
        if ($minimum === null && ($restrictions->minimum_amount ?? null) !== null) {
            if (strtolower((string)$restrictions->minimum_amount_currency) !== $currency) {
                throw new \moodle_exception('invoicepromotioninvalid', 'paygw_stripe');
            }
            $minimum = $restrictions->minimum_amount;
        }
        if ($minimum !== null && $amount < (int)$minimum) {
            throw new \moodle_exception('invoicepromotioninvalid', 'paygw_stripe');
        }
        if (!empty($restrictions->first_time_transaction)) {
            // Stripe treats an initiated PaymentIntent or a subscription trial as prior activity.
            $intents = $this->stripe->paymentIntents->all(['customer' => $customerid, 'limit' => 1]);
            $subscriptions = $this->stripe->subscriptions->all(['customer' => $customerid, 'status' => 'all', 'limit' => 1]);
            $invoices = $this->stripe->invoices->all(['customer' => $customerid, 'status' => 'paid', 'limit' => 1]);
            if ($intents->data || $subscriptions->data || $invoices->data) {
                throw new \moodle_exception('invoicepromotioninvalid', 'paygw_stripe');
            }
        }
        if ($coupon->percent_off !== null) {
            $basispoints = (int)round((float)$coupon->percent_off * 100);
            if ($basispoints <= 0 || $basispoints > 10000) {
                throw new \moodle_exception('invoicepromotioninvalid', 'paygw_stripe');
            }
            $discount = (int)round($amount * $basispoints / 10000);
        } else {
            $discount = $coupon->currency_options->{$currency}->amount_off ?? null;
            if ($discount === null && strtolower((string)$coupon->currency) === $currency) {
                $discount = $coupon->amount_off;
            }
            if ($discount === null) {
                throw new \moodle_exception('invoicepromotioninvalid', 'paygw_stripe');
            }
            $discount = (int)$discount;
            if ($discount <= 0) {
                throw new \moodle_exception('invoicepromotioninvalid', 'paygw_stripe');
            }
        }
        return [
            'promotioncodeid' => $promotion->id,
            'code' => $promotion->code,
            'amount' => max(0, $amount - $discount),
        ];
    }
}
