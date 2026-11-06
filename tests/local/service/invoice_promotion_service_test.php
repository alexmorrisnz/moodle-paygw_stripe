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

use paygw_stripe\tests\fixtures\invoice_http_client;
use Stripe\ApiRequestor;
use Stripe\StripeClient;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/payment/gateway/stripe/.extlib/stripe-php/init.php');
require_once(__DIR__ . '/../../fixtures/invoice_http_client.php');

/**
 * Restriction and money tests using the real SDK and an isolated HTTP fixture.
 *
 * @package paygw_stripe
 * @category test
 * @covers \paygw_stripe\local\service\invoice_promotion_service
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class invoice_promotion_service_test extends \basic_testcase {
    /** @var invoice_http_client */
    private invoice_http_client $http;
    /** @var invoice_promotion_service */
    private invoice_promotion_service $service;
    /** @var mixed Original SDK transport. */
    private $originalhttp;

    protected function setUp(): void {
        parent::setUp();
        $property = new \ReflectionProperty(ApiRequestor::class, '_httpClient');
        $property->setAccessible(true);
        $this->originalhttp = $property->getValue();
        $this->http = new invoice_http_client();
        ApiRequestor::setHttpClient($this->http);
        $this->service = new invoice_promotion_service(new StripeClient('sk_test_fake'));
        $this->http->promotioncodes['promo_20'] = [
            'id' => 'promo_20', 'object' => 'promotion_code', 'code' => 'KURS20', 'active' => true,
            'promotion' => ['type' => 'coupon', 'coupon' => 'coupon_20'], 'customer' => null,
            'customer_account' => null, 'expires_at' => null, 'max_redemptions' => null, 'times_redeemed' => 0,
            'restrictions' => ['first_time_transaction' => false, 'minimum_amount' => null,
                'minimum_amount_currency' => null],
        ];
        $this->http->coupons['coupon_20'] = [
            'id' => 'coupon_20', 'object' => 'coupon', 'valid' => true, 'percent_off' => 20,
            'currency' => null, 'currency_options' => null, 'amount_off' => null,
            'redeem_by' => null, 'max_redemptions' => null, 'times_redeemed' => 0, 'applies_to' => null,
        ];
    }

    protected function tearDown(): void {
        ApiRequestor::setHttpClient($this->originalhttp);
        parent::tearDown();
    }

    /**
     * Get a default valid quote.
     *
     * @return array Default valid quote.
     */
    private function quote(): array {
        return $this->service->quote(' kurs20 ', 10000, 'EUR', 'cus_learner', 'prod_course');
    }

    /**
     * Assert a rejection with no invoice or redemption side effects.
     */
    private function invalid(): void {
        try {
            $this->quote();
            $this->fail('Expected an invalid code');
        } catch (\moodle_exception $e) {
            $this->assertSame('invoicepromotioninvalid', $e->errorcode);
        }
        $this->assertSame([], $this->http->invoices);
        $this->assertSame(0, $this->http->promotioncodes['promo_20']['times_redeemed']);
    }

    public function test_percentage_code_is_case_insensitive_and_does_not_redeem(): void {
        $this->assertSame(['promotioncodeid' => 'promo_20', 'code' => 'KURS20', 'amount' => 8000], $this->quote());
        $this->assertSame([], $this->http->invoices);
        $this->assertSame(0, $this->http->promotioncodes['promo_20']['times_redeemed']);
    }

    public function test_percentage_code_remains_valid_when_estimate_rounds_to_zero(): void {
        $this->http->coupons['coupon_20']['percent_off'] = 1;
        $quote = $this->service->quote('KURS20', 1, 'JPY', 'cus_learner', 'prod_course');
        $this->assertSame('promo_20', $quote['promotioncodeid']);
        $this->assertSame(1, $quote['amount']);
    }

    public function test_zero_percentage_is_not_a_valid_discount(): void {
        $this->http->coupons['coupon_20']['percent_off'] = 0;
        $this->invalid();
    }

    public function test_fixed_discount_is_capped_at_zero_and_currency_checked(): void {
        $this->http->coupons['coupon_20']['percent_off'] = null;
        $this->http->coupons['coupon_20']['amount_off'] = 20000;
        $this->http->coupons['coupon_20']['currency'] = 'eur';
        $this->assertSame(0, $this->quote()['amount']);
        $this->http->coupons['coupon_20']['currency'] = 'usd';
        $this->invalid();
    }

    public function test_multi_currency_fixed_coupon_uses_the_purchase_currency(): void {
        $this->http->coupons['coupon_20']['percent_off'] = null;
        $this->http->coupons['coupon_20']['currency_options'] = ['eur' => ['amount_off' => 1250]];
        $this->assertSame(8750, $this->quote()['amount']);
    }

    public function test_wrong_customer_cannot_redeem_a_restricted_code(): void {
        $this->http->promotioncodes['promo_20']['customer'] = 'cus_someone_else';
        $this->invalid();
        $this->http->promotioncodes['promo_20']['customer'] = 'cus_learner';
        $this->assertSame(8000, $this->quote()['amount']);
    }

    public function test_expired_inactive_and_exhausted_codes_are_rejected(): void {
        $this->http->promotioncodes['promo_20']['expires_at'] = time() - 1;
        $this->invalid();
        $this->http->promotioncodes['promo_20']['expires_at'] = null;
        $this->http->promotioncodes['promo_20']['active'] = false;
        $this->invalid();
        $this->http->promotioncodes['promo_20']['active'] = true;
        $this->http->promotioncodes['promo_20']['max_redemptions'] = 1;
        $this->http->promotioncodes['promo_20']['times_redeemed'] = 1;
        try {
            $this->quote();
            $this->fail('Expected exhaustion to reject the code');
        } catch (\moodle_exception $e) {
            $this->assertSame('invoicepromotioninvalid', $e->errorcode);
        }
    }

    public function test_invalid_or_expired_coupon_is_rejected(): void {
        $this->http->coupons['coupon_20']['valid'] = false;
        $this->invalid();
        $this->http->coupons['coupon_20']['valid'] = true;
        $this->http->coupons['coupon_20']['redeem_by'] = time() - 1;
        $this->invalid();
    }

    public function test_product_restrictions_are_checked_before_billing(): void {
        $this->http->coupons['coupon_20']['applies_to'] = ['products' => ['prod_other']];
        $this->invalid();
        $this->http->coupons['coupon_20']['applies_to'] = ['products' => ['prod_course']];
        $this->assertSame(8000, $this->quote()['amount']);
    }

    public function test_minimum_spend_and_its_currency_are_checked(): void {
        $this->http->promotioncodes['promo_20']['restrictions']['minimum_amount'] = 10001;
        $this->http->promotioncodes['promo_20']['restrictions']['minimum_amount_currency'] = 'eur';
        $this->invalid();
        $this->http->promotioncodes['promo_20']['restrictions']['minimum_amount'] = 10000;
        $this->assertSame(8000, $this->quote()['amount']);
        $this->http->promotioncodes['promo_20']['restrictions']['minimum_amount_currency'] = 'usd';
        $this->invalid();
    }

    public function test_first_purchase_code_rejects_even_an_unfinished_previous_payment(): void {
        $this->http->promotioncodes['promo_20']['restrictions']['first_time_transaction'] = true;
        $this->assertSame(8000, $this->quote()['amount']);
        $this->http->paymentintents['pi_old'] = ['id' => 'pi_old', 'object' => 'payment_intent',
            'customer' => 'cus_learner', 'status' => 'requires_payment_method'];
        $this->invalid();
    }

    public function test_first_purchase_code_rejects_previous_trials(): void {
        $this->http->promotioncodes['promo_20']['restrictions']['first_time_transaction'] = true;
        $this->http->subscriptions['sub_old'] = ['id' => 'sub_old', 'object' => 'subscription',
            'customer' => 'cus_learner', 'status' => 'canceled'];
        $this->invalid();
    }

    public function test_server_id_is_rechecked_after_a_quote(): void {
        $id = $this->quote()['promotioncodeid'];
        $this->http->promotioncodes[$id]['active'] = false;
        try {
            $this->service->quote_by_id($id, 10000, 'eur', 'cus_learner', 'prod_course');
            $this->fail('A stale selection must not start billing');
        } catch (\moodle_exception $e) {
            $this->assertSame('invoicepromotioninvalid', $e->errorcode);
        }
    }
}
