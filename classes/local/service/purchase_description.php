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

/**
 * Resolves purchase labels without trusting browser-supplied descriptions.
 *
 * @package paygw_stripe
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class purchase_description {
    /**
     * Resolve a plain-text purchase label from server-side purchase identifiers.
     *
     * @param string $component Payment component
     * @param string $paymentarea Payment area
     * @param int $itemid Purchased item
     * @return string
     */
    public static function get(string $component, string $paymentarea, int $itemid): string {
        global $DB;

        if ($component === 'enrol_fee' && $paymentarea === 'fee') {
            $instance = $DB->get_record('enrol', ['enrol' => 'fee', 'id' => $itemid], '*', MUST_EXIST);
            $course = get_course($instance->courseid);
            $name = format_string($course->fullname, true, [
                'context' => \context_course::instance($course->id),
            ]);
            return trim(html_to_text(get_string('purchasedescription', 'enrol_fee', $name), 0, false));
        }

        // Moodle's payment-provider interface has no trusted description callback.
        return get_string('purchasereference', 'paygw_stripe', (object)[
            'component' => $component,
            'paymentarea' => $paymentarea,
            'itemid' => $itemid,
        ]);
    }
}
