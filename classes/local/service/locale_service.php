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

/**
 * Locale service.
 *
 * @copyright 2026 Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class locale_service {
    /**
     * Map/normalise Moodle language codes to Stripe locales.
     *
     * Stripe Checkout "locale" must be one of Stripe's supported locales (or "auto").
     * Customer "preferred_locales" should also use Stripe-supported locales.
     *
     * @param string $moodlelang e.g. "en", "de", "en_us", "pt_br"
     * @return string Stripe locale, falling back safely to "en"
     */
    public function map_moodle_lang_to_stripe_locale(string $moodlelang): string {
        if (get_config('paygw_stripe', 'forcedlocale') != '') {
            return get_config('paygw_stripe', 'forcedlocale');
        }

        $moodlelang = strtolower(trim($moodlelang));

        // Common/known exceptions and explicit mappings (extend as needed).
        $explicit = [
            'no' => 'nb',
            'pt_br' => 'pt-BR',
            'zh_cn' => 'zh-Hans',
            'zh_tw' => 'zh-Hant',

            // Moodle variants that should still be English in Stripe.
            'en_us' => 'en',
            'en_au' => 'en',
        ];

        if (isset($explicit[$moodlelang])) {
            return $explicit[$moodlelang];
        }

        // Stripe supported locales whitelist (keeps us from passing invalid values).
        $supported = [
            'bg', 'cs', 'da', 'de', 'el', 'en', 'en-GB', 'es', 'es-419', 'et', 'fi', 'fil', 'fr', 'fr-CA',
            'hr', 'hu', 'id', 'it', 'ja', 'ko', 'lt', 'lv', 'ms', 'mt', 'nb', 'nl', 'pl', 'pt', 'pt-BR',
            'ro', 'ru', 'sk', 'sl', 'sv', 'th', 'tr', 'vi', 'zh', 'zh-HK', 'zh-Hans', 'zh-Hant', 'zh-TW',
        ];

        // Exact match (e.g. "de", "fr").
        if (in_array($moodlelang, $supported, true)) {
            return $moodlelang;
        }

        // Normalise underscores to hyphens: en_us -> en-us, fr_ca -> fr-ca.
        $normalised = str_replace('_', '-', $moodlelang);

        // Convert language-region to Stripe-style casing: en-us -> en-US.
        if (preg_match('/^([a-z]{2})-([a-z]{2})$/', $normalised, $m)) {
            $candidate = $m[1] . '-' . strtoupper($m[2]);
            if (in_array($candidate, $supported, true)) {
                return $candidate;
            }
        }

        // If Moodle code is a variant like "de_kids" or "es_mx_kids", fall back to base language.
        $base = preg_split('/[_-]/', $moodlelang, 2)[0] ?? 'en';
        if (in_array($base, $supported, true)) {
            return $base;
        }

        return 'en';
    }

    /**
     * Get the Stripe locale to use for this request, preferring user language with site fallback.
     *
     * @param \stdClass $user
     * @return string
     */
    public function get_stripe_locale_for_user(\stdClass $user): string {
        if (isset($user->lang) && is_string($user->lang) && $user->lang !== '') {
            $lang = $user->lang;
        } else {
            $lang = current_language();
        }
        return $this->map_moodle_lang_to_stripe_locale($lang);
    }
}
