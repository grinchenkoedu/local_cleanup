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

namespace local_cleanup;

use dml_exception;
use moodle_database;
use moodle_recordset;
use core_user\fields;

/**
 * File finder class for the cleanup plugin.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finder {

    /** Default limit for file queries */
    const LIMIT_DEFAULT = 50;

    /**
     * Database connection instance.
     *
     * @var moodle_database
     */
    private $db;

    /**
     * Constructor.
     *
     * @param moodle_database $db Database connection
     */
    public function __construct(moodle_database $db) {
        $this->db = $db;
    }

    /**
     * Find files based on criteria.
     *
     * @param int $limit Maximum number of records to return
     * @param int $offset Offset for pagination
     * @param array $filter Filter criteria
     * @return moodle_recordset Recordset of matching files
     */
    public function find(int $limit = self::LIMIT_DEFAULT, int $offset = 0, array $filter = []): moodle_recordset {
        return $this->db->get_recordset_sql(
            $this->get_search_sql($filter, false, $limit, $offset),
            $this->get_search_values($filter)
        );
    }

    /**
     * Count files matching the given filter criteria.
     *
     * @param array $filter Filter criteria
     * @return int Number of matching files
     */
    public function count(array $filter = []): int {
        return (int)$this->db->get_field_sql(
            $this->get_search_sql($filter, true),
            $this->get_search_values($filter)
        );
    }

    /**
     * Get statistics for files by component.
     *
     * @param string $component Component name
     * @param string|null $until Date string for filtering (e.g., '-1 year')
     * @param bool $newerthan If true, get files newer than $until; if false, get files older than $until
     * @param string|null $from Date string for filtering (e.g., '-2 years')
     * @return object {count: int, size: int (bytes)}
     *
     * @throws dml_exception
     */
    public function stats(string $component, ?string $until = null, bool $newerthan = false, ?string $from = null) {
        $sql = '
            SELECT
                COUNT(f.id) as "count",
                COALESCE(SUM(f.filesize), 0) as "size"
            FROM {files} f
            WHERE f.component = ?
        ';

        // For backup component, use timemodified instead of timecreated.
        $timefield = ($component === 'backup') ? 'f.timemodified' : 'f.timecreated';

        // If both from and until are provided, get files in the specific time period.
        if ($from !== null && $until !== null) {
            $fromtimestamp = strtotime($from);
            $untiltimestamp = strtotime($until);
            $sql .= " AND $timefield >= $fromtimestamp AND $timefield < $untiltimestamp";

        } else if ($until !== null) {
            $operator = $newerthan ? '>' : '<';
            $sql .= " AND $timefield $operator " . strtotime($until);
        }

        return $this->db->get_record_sql($sql, [$component]);
    }

    /**
     * Get search parameter values for SQL queries.
     *
     * @param array $filter Filter criteria
     * @return array Parameter values for SQL query
     */
    private function get_search_values(array $filter): array {
        $values = [];

        if (!empty($filter['name_like'])) {
            $values['name_like'] = '%' . $filter['name_like'] . '%';
        }

        if (!empty($filter['user_like'])) {
            $values['user_like'] = '%' . $filter['user_like'] . '%';
        }

        if (!empty($filter['component'])) {
            $values['component'] = $filter['component'];
        }

        return $values;
    }

    /**
     * Build SQL query for file search.
     *
     * @param array $filter Filter criteria
     * @param bool $count Whether this is for counting (true) or selecting records (false)
     * @param int $limit Maximum number of records to return
     * @param int $offset Offset for pagination
     * @return string SQL query
     */
    private function get_search_sql(
        array $filter, bool
        $count = false,
        int $limit = self::LIMIT_DEFAULT,
        $offset = 0
    ): string {
        $where = [
            sprintf('f.filesize > %d', ($filter['filesize'] ?? 0) * 1024 * 1024),
        ];

        if (!empty($filter['component'])) {
            $where[] = 'f.component = :component';
        }

        if (!empty($filter['name_like'])) {
            $where[] = 'f.filename LIKE :name_like';
        }

        if (!empty($filter['user_like'])) {
            // Use database-agnostic concatenation via Moodle's sql_concat.
            $fullname1 = $this->db->sql_concat('u.firstname', "' '", 'u.lastname');
            $fullname2 = $this->db->sql_concat('u.lastname', "' '", 'u.firstname');
            $where[] = "($fullname1 LIKE :user_like"
                      . " OR $fullname2 LIKE :user_like"
                      . " OR f.author LIKE :user_like)";
        }

        if (!empty($filter['user_deleted'])) {
            $where[] = 'u.deleted = 1';
        }

        if ($count) {
            return sprintf(
                'SELECT COUNT(f.id) FROM {files} f LEFT JOIN {user} u ON f.userid = u.id WHERE %s',
                implode(' AND ', $where)
            );
        }

        $userfields = fields::for_name()
            ->get_sql('u', false, '', '', false)
            ->selects;

        return sprintf(
            'SELECT %s FROM {files} f LEFT JOIN {user} u ON f.userid = u.id WHERE %s GROUP BY f.contenthash %s',
            'f.*, u.deleted as user_deleted, ' . $userfields,
            implode(' AND ', $where),
            $offset > 0 ? sprintf('LIMIT %d OFFSET %d', $limit, $offset) : sprintf('LIMIT %d', $limit)
        );
    }
}
