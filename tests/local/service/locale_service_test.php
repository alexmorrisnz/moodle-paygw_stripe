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

/**
 * Tests for locale service logic.
 *
 * @package    paygw_stripe
 * @category   test
 * @copyright  2026 Alex Morris
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace paygw_stripe\local\service;

use advanced_testcase;

/**
 * Tests for locale_service.
 */
final class locale_service_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Data provider for locale mapping.
     *
     * @return array
     */
    public static function locale_mapping_provider(): array {
        return [
            'explicit norwegian' => ['no', 'nb'],
            'explicit brazilian portuguese' => ['pt_br', 'pt-BR'],
            'explicit simplified chinese' => ['zh_cn', 'zh-Hans'],
            'explicit traditional chinese' => ['zh_tw', 'zh-Hant'],
            'explicit english variant fallback' => ['en_us', 'en'],
            'normalised supported locale' => ['en_gb', 'en-GB'],
            'trim and lowercase before mapping' => [' EN_gb ', 'en-GB'],
            'variant falls back to base language' => ['de_kids', 'de'],
            'unknown falls back to english' => ['zz_xx', 'en'],
        ];
    }

    /**
     * Tests Moodle locale mapping to Stripe locale.
     *
     * @dataProvider locale_mapping_provider
     * @param string $moodlelang
     * @param string $expected
     * @covers \paygw_stripe\local\service\locale_service::map_moodle_lang_to_stripe_locale
     */
    public function test_map_moodle_lang_to_stripe_locale(string $moodlelang, string $expected): void {
        set_config('forcedlocale', '', 'paygw_stripe');
        $service = new locale_service();

        $this->assertSame($expected, $service->map_moodle_lang_to_stripe_locale($moodlelang));
    }

    /**
     * Tests forced locale overrides language mapping.
     * @covers \paygw_stripe\local\service\locale_service::map_moodle_lang_to_stripe_locale
     */
    public function test_map_moodle_lang_to_stripe_locale_forced_config(): void {
        set_config('forcedlocale', 'fr-CA', 'paygw_stripe');
        $service = new locale_service();

        $this->assertSame('fr-CA', $service->map_moodle_lang_to_stripe_locale('en'));
    }

    /**
     * Tests get_stripe_locale_for_user with explicit user language.
     * @covers \paygw_stripe\local\service\locale_service::get_stripe_locale_for_user
     */
    public function test_get_stripe_locale_for_user_uses_user_language(): void {
        set_config('forcedlocale', '', 'paygw_stripe');
        $service = new locale_service();
        $user = (object)['lang' => 'fr_ca'];

        $this->assertSame('fr-CA', $service->get_stripe_locale_for_user($user));
    }

    /**
     * Tests get_stripe_locale_for_user falls back when user language is empty.
     * @covers \paygw_stripe\local\service\locale_service::get_stripe_locale_for_user
     */
    public function test_get_stripe_locale_for_user_falls_back_to_current_language(): void {
        set_config('forcedlocale', '', 'paygw_stripe');
        $service = new locale_service();
        $user = (object)['lang' => ''];

        $expected = $service->map_moodle_lang_to_stripe_locale(current_language());
        $this->assertSame($expected, $service->get_stripe_locale_for_user($user));
    }
}
