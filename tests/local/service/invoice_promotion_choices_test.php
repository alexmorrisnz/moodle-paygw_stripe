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

namespace paygw_stripe\local\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Cross-user, cross-course, expiry and price-binding tests for opaque choices.
 *
 * @package paygw_stripe
 * @category test
 * @covers \paygw_stripe\local\service\invoice_promotion_choices
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class invoice_promotion_choices_test extends \basic_testcase {
    /** @var array Original globals. */
    private array $original;
    /** @var array Purchase identity. */
    private array $purchase = ['component' => 'enrol_fee', 'paymentarea' => 'fee', 'itemid' => 42,
        'paymentaccountid' => 7, 'amount' => 10000, 'currency' => 'eur'];

    protected function setUp(): void {
        parent::setUp();
        global $CFG, $SESSION, $USER;
        $this->original = [$SESSION, $USER, $CFG->sessiontimeout];
        $SESSION = new \stdClass();
        $USER = (object)['id' => 13];
        $CFG->sessiontimeout = 3600;
    }

    protected function tearDown(): void {
        global $CFG, $SESSION, $USER;
        [$SESSION, $USER, $CFG->sessiontimeout] = $this->original;
        parent::tearDown();
    }

    public function test_choice_is_opaque_and_can_be_reused_for_a_network_retry(): void {
        $token = invoice_promotion_choices::save($this->purchase, 'promo_server');
        $this->assertSame(64, strlen($token));
        $choice = invoice_promotion_choices::read($token, $this->purchase);
        $this->assertSame('promo_server', $choice['promotioncodeid']);
        $this->assertSame($choice, invoice_promotion_choices::read($token, $this->purchase));
    }

    public function test_a_different_user_cannot_reuse_a_choice(): void {
        global $USER;
        $token = invoice_promotion_choices::save($this->purchase, 'promo_server');
        $USER = (object)['id' => 14];
        $this->assertNull(invoice_promotion_choices::read($token, $this->purchase));
    }

    public function test_course_account_currency_and_price_are_bound(): void {
        $token = invoice_promotion_choices::save($this->purchase, 'promo_server');
        foreach (['itemid' => 43, 'paymentaccountid' => 8, 'currency' => 'usd', 'amount' => 9000] as $key => $value) {
            $other = $this->purchase;
            $other[$key] = $value;
            $this->assertNull(invoice_promotion_choices::read($token, $other));
        }
    }

    public function test_expired_and_forged_tokens_are_rejected(): void {
        global $SESSION;
        $token = invoice_promotion_choices::save($this->purchase, 'promo_server');
        $SESSION->paygw_stripe_promotion_choices[$token]['expires'] = time() - 1;
        $this->assertNull(invoice_promotion_choices::read($token, $this->purchase));
        $this->assertNull(invoice_promotion_choices::read(str_repeat('f', 64), $this->purchase));
        $this->assertNull(invoice_promotion_choices::read('promo_client_supplied', $this->purchase));
    }

    public function test_no_discount_is_an_explicit_choice_and_storage_is_bounded(): void {
        global $SESSION;
        for ($i = 0; $i < 25; $i++) {
            $token = invoice_promotion_choices::save($this->purchase, null);
        }
        $this->assertCount(20, $SESSION->paygw_stripe_promotion_choices);
        $this->assertNull(invoice_promotion_choices::read($token, $this->purchase)['promotioncodeid']);
    }
}
