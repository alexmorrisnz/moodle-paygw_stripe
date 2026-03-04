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
 * paygw_stripe installer script.
 *
 * @package    paygw_stripe
 * @copyright  2026 Jayce Birrell <jayce.birrell@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Enable the Stripe payment gateway plugin on installation.
 *
 * It still needs to be configured and enabled for payment accounts.
 */
function xmldb_paygw_stripe_install() {
    global $CFG;

    // Enable the Stripe payment gateway on installation. It still needs to be configured and enabled for accounts.
    $order = (!empty($CFG->paygw_plugins_sortorder)) ? explode(',', $CFG->paygw_plugins_sortorder) : [];
    set_config('paygw_plugins_sortorder', join(',', array_merge($order, ['stripe'])));
}
