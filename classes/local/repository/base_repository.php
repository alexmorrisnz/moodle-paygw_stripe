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

use moodle_database;
use paygw_stripe\local\model\mappable_model;

/**
 * Base repository that provides basic CRUD operations.
 *
 * @template  T of mappable_model
 * @copyright 2026 Alex Morris <alex@navra.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_repository {
    public function __construct(protected ?moodle_database $db = null) {
        global $DB;
        $this->db ??= $DB;
    }

    /**
     * Returns the table name for the model.
     *
     * @return string
     */
    abstract protected function table(): string;

    /**
     * Returns the model class name.
     *
     * @return class-string<T>
     */
    abstract protected function model_class(): string;

    /**
     * Find a model by its ID.
     *
     * @param int $id
     * @return T|null
     */
    public function find_by_id(int $id): ?mappable_model {
        $record = $this->db->get_record($this->table(), ['id' => $id]);
        return $record ? $this->hydrate($record) : null;
    }

    /**
     * Save a model.
     *
     * @param T $model
     * @return T
     */
    public function save(mappable_model $model): mappable_model {
        $record = $model->to_record();
        if ($record->id === null) {
            unset($record->id);
            $record->id = $this->db->insert_record($this->table(), $record);
        } else {
            $this->db->update_record($this->table(), $record);
        }
        return $this->hydrate($record);
    }

    /**
     * Delete a modal.
     *
     * @param mappable_model $model
     * @return void
     * @throws \dml_exception
     */
    public function delete(mappable_model $model): void {
        $this->db->delete_records($this->table(), ['id' => $model->id]);
    }

    /**
     * Hydrate a record into a model.
     *
     * @param \stdClass $record
     * @return T
     */
    protected function hydrate(\stdClass $record): mappable_model {
        $class = $this->model_class();
        return $class::from_record($record);
    }
}
