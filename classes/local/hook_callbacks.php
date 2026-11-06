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

namespace paygw_stripe\local;

/**
 * Initialise invoice code entry wherever core payment buttons are used.
 *
 * @package paygw_stripe
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class hook_callbacks {
    /**
     * Load the editor before the purchaser opens a gateway modal.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook Output hook.
     */
    public static function invoice_promotions(\core\hook\output\before_standard_head_html_generation $hook): void {
        global $PAGE;
        if (!during_initial_install() && isloggedin() && !isguestuser() && get_config('paygw_stripe', 'version')) {
            $PAGE->requires->js_call_amd('paygw_stripe/invoice_promotions', 'init');
        }
    }
}
