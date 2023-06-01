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
 * Lib functions.
 *
 * @package    paygw_stripe
 * @author     Alex Morris <alex@navra.nz>
 * @copyright  2023 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * User profile page callback.
 *
 * Used add a section about the subscriptions.
 *
 * @param \core_user\output\myprofile\tree $tree My profile tree where the setting will be added.
 * @param stdClass $user The user object.
 * @param bool $iscurrentuser Is this the current user viewing
 * @return void Return if the mobile web services setting is disabled or if not the current user.
 */
function paygw_stripe_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser) {
    if (!$iscurrentuser) {
        return;
    }

    $tree->add_category(new core_user\output\myprofile\category('paygw_stripe', get_string('profilecat', 'paygw_stripe'),
        'loginactivity'));
    $tree->add_node(new core_user\output\myprofile\node('paygw_stripe', 'cancelsubscriptions',
        get_string('cancelsubscriptions', 'paygw_stripe'), null, new moodle_url('/payment/gateway/stripe/subscriptions.php')));
}
