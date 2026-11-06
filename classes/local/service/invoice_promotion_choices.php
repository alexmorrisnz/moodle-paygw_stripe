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

/**
 * Short-lived session choices, bound to the authenticated purchaser and original price.
 *
 * @package paygw_stripe
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class invoice_promotion_choices {
    /**
     * Store a server-validated selection. The browser only receives an opaque token.
     *
     * @param array $purchase Purchase identity, including original amount and currency.
     * @param string|null $promotioncodeid Validated promotion code ID, or null for no code.
     * @return string Token.
     */
    public static function save(array $purchase, ?string $promotioncodeid): string {
        global $SESSION, $USER, $CFG;
        $choices = (array)($SESSION->paygw_stripe_promotion_choices ?? []);
        $choices = array_filter($choices, static fn($choice) => $choice['expires'] >= time());
        while (count($choices) >= 20) {
            array_shift($choices);
        }
        $token = bin2hex(random_bytes(32));
        $choices[$token] = [
            'purchase' => $purchase,
            'userid' => (int)$USER->id,
            'promotioncodeid' => $promotioncodeid,
            'expires' => time() + min((int)$CFG->sessiontimeout, 15 * MINSECS),
        ];
        $SESSION->paygw_stripe_promotion_choices = $choices;
        return $token;
    }

    /**
     * Read without consuming, so a network retry can resume the same billing request.
     *
     * @param string $token Opaque token.
     * @param array $purchase Current server-derived identity and price.
     * @return array|null Matching selection.
     */
    public static function read(string $token, array $purchase): ?array {
        global $SESSION, $USER;
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return null;
        }
        $choice = $SESSION->paygw_stripe_promotion_choices[$token] ?? null;
        if (
            !$choice || $choice['expires'] < time() || $choice['userid'] !== (int)$USER->id ||
            $choice['purchase'] !== $purchase
        ) {
            return null;
        }
        return $choice;
    }
}
