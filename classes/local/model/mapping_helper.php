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

namespace paygw_stripe\local\model;

/**
 * Mapping helper trait. Provides common methods for mapping data.
 *
 * @package   paygw_stripe
 * @copyright Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait mapping_helper {
    /**
     * Convert a value to a nullable string.
     *
     * @param mixed $value
     * @return string|null
     */
    protected static function to_nullable_string(mixed $value): ?string {
        return $value === null ? null : (string)$value;
    }

    /**
     * Get a nullable string field from a record.
     *
     * @param \stdClass $record
     * @param string $field
     * @return string|null
     */
    protected static function nullable_string_field(\stdClass $record, string $field): ?string {
        return property_exists($record, $field) ? self::to_nullable_string($record->{$field}) : null;
    }

    /**
     * Convert a value to a nullable integer.
     *
     * @param mixed $value
     * @return int|null
     */
    protected static function to_nullable_int(mixed $value): ?int {
        return $value === null ? null : (int)$value;
    }

    /**
     * Get a nullable integer field from a record.
     *
     * @param \stdClass $record
     * @param string $field
     * @return int|null
     */
    protected static function nullable_int_field(\stdClass $record, string $field): ?int {
        return property_exists($record, $field) ? self::to_nullable_int($record->{$field}) : null;
    }

    /**
     * Convert a value to a boolean.
     *
     * @param mixed $value
     * @return bool
     */
    protected static function to_bool(mixed $value): bool {
        return (bool)$value;
    }

    /**
     * Convert a boolean value to an integer.
     *
     * @param bool $value
     * @return int
     */
    protected static function from_bool(bool $value): int {
        return (int)$value;
    }
}
