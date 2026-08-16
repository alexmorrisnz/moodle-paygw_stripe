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

namespace paygw_stripe\local\repository;

use paygw_stripe\local\model\checkout_session;

/**
 * Checkout session table repository.
 *
 * @phpcs:ignore moodle.Commenting.ValidTags.Invalid
 * @extends   base_repository<checkout_session>
 * @package   paygw_stripe
 * @copyright Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class checkout_session_repository extends base_repository {
    /**
     * Returns the table name for the model.
     *
     * @return string
     */
    protected function table(): string {
        return 'paygw_stripe_checkout_sessions';
    }

    /**
     * Returns the model class name.
     *
     * @return class-string<checkout_session>
     */
    protected function model_class(): string {
        return checkout_session::class;
    }

    /**
     * Find a checkout session record by sessionid.
     *
     * @param string $sessionid
     * @return checkout_session|null
     * @throws \dml_exception
     */
    public function find_by_sessionid(string $sessionid): ?checkout_session {
        global $DB;
        $record = $DB->get_record($this->table(), ['checkoutsessionid' => $sessionid]);
        if (!$record) {
            return null;
        }
        return $this->hydrate($record);
    }
}
