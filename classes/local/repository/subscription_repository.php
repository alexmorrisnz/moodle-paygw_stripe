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

use paygw_stripe\local\model\subscription;

/**
 * Subscription table repository.
 *
 * @phpcs:ignore moodle.Commenting.ValidTags.Invalid
 * @extends   base_repository<subscription>
 * @copyright 2026 Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class subscription_repository extends base_repository {
    /**
     * Returns the table name for the model.
     *
     * @return string
     */
    protected function table(): string {
        return 'paygw_stripe_subscriptions';
    }

    /**
     * Returns the model class name.
     *
     * @return class-string<subscription>
     */
    protected function model_class(): string {
        return subscription::class;
    }

    /**
     * Find all subscriptions by Moodle userid.
     *
     * @param int $userid
     * @return array
     * @throws \dml_exception
     */
    public function find_all_by_userid(int $userid): array {
        global $DB;

        $records = $DB->get_records('paygw_stripe_subscriptions', ['userid' => $userid]);
        return array_map([$this, 'hydrate'], $records);
    }

    /**
     * Find a subscription by Stripe subscription id.
     *
     * @param string $subscriptionid
     * @return subscription|null
     * @throws \dml_exception
     */
    public function find_by_subscriptionid(string $subscriptionid): ?subscription {
        global $DB;
        $record = $DB->get_record($this->table(), ['subscriptionid' => $subscriptionid]);
        return $record ? $this->hydrate($record) : null;
    }
}
