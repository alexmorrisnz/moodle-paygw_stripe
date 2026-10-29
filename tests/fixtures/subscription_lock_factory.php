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

namespace paygw_stripe\tests\fixtures;

/**
 * Real file locks with immediate contention reporting and a simulated waiting writer.
 *
 * @package paygw_stripe
 * @category test
 * @copyright 2026 Moodle Stripe contributors
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subscription_lock_factory extends \core\lock\file_lock_factory {
    /** @var \Closure|null Simulate another request committing before lock acquisition. */
    public static ?\Closure $beforelock = null;

    /**
     * Acquire a real lock without a ten-second wait in contention tests.
     *
     * @param string $resource Lock key
     * @param int $timeout Requested timeout
     * @param int $maxlifetime Maximum lifetime
     * @return \core\lock\lock|false
     */
    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        if (self::$beforelock) {
            $callback = self::$beforelock;
            self::$beforelock = null;
            $callback();
        }
        return parent::get_lock($resource, 0, $maxlifetime);
    }
}
