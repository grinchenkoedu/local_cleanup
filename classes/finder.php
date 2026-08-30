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
            $this->get_search_sql($filter),
            $this->get_search_values($filter),
            $offset,
            $limit
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
        // Every file area carries a "." directory record with no content. It is not a file and
        // must not be counted as one.
        $sql = '
            SELECT
                COUNT(f.id) as "count",
                COALESCE(SUM(f.filesize), 0) as "size"
            FROM {files} f
            WHERE f.component = :component
              AND f.filename <> :directory
        ';
        $params = [
            'component' => $component,
            'directory' => '.',
        ];

        // For backup component, use timemodified instead of timecreated.
        $timefield = ($component === 'backup') ? 'f.timemodified' : 'f.timecreated';

        // If both from and until are provided, get files in the specific time period.
        if ($from !== null && $until !== null) {
            $sql .= " AND $timefield >= :fromtime AND $timefield < :untiltime";
            $params['fromtime'] = strtotime($from);
            $params['untiltime'] = strtotime($until);
        } else if ($until !== null) {
            $operator = $newerthan ? '>' : '<';
            $sql .= " AND $timefield $operator :untiltime";
            $params['untiltime'] = strtotime($until);
        }

        return $this->db->get_record_sql($sql, $params);
    }

    /**
     * Get search parameter values for SQL queries.
     *
     * @param array $filter Filter criteria
     * @return array Parameter values for SQL query
     */
    private function get_search_values(array $filter): array {
        $values = [
            'filesize' => ($filter['filesize'] ?? 0) * 1024 * 1024,
        ];

        if (!empty($filter['name_like'])) {
            $values['name_like'] = '%' . $this->db->sql_like_escape($filter['name_like']) . '%';
        }

        if (!empty($filter['user_like'])) {
            // The owner clause tests three expressions, and a named parameter is bound once
            // each, so the same value is supplied under three names.
            $userlike = '%' . $this->db->sql_like_escape($filter['user_like']) . '%';
            $values['user_like'] = $userlike;
            $values['user_like_reversed'] = $userlike;
            $values['user_like_author'] = $userlike;
        }

        if (!empty($filter['component'])) {
            $values['component'] = $filter['component'];
        }

        return $values;
    }

    /**
     * The search, in the pieces table_sql wants: fields, from, where and parameters.
     *
     * The report page builds its table from these rather than from find(), so there is one
     * definition of what a search means and the page cannot drift from the programmatic API.
     *
     * @param array $filter Filter criteria
     * @return array{fields: string, from: string, where: string, params: array} The pieces
     */
    public function get_sql_parts(array $filter): array {
        $userfields = fields::for_name()
            ->get_sql('u', false, '', '', false)
            ->selects;

        return [
            'fields' => 'f.*, u.deleted AS user_deleted, ' . $userfields,
            'from' => '{files} f LEFT JOIN {user} u ON f.userid = u.id',
            'where' => implode(' AND ', $this->get_where($filter)),
            'params' => $this->get_search_values($filter),
        ];
    }

    /**
     * The conditions a filter imposes.
     *
     * @param array $filter Filter criteria
     * @return string[] Conditions, to be joined with AND
     */
    private function get_where(array $filter): array {
        $where = [
            'f.filesize > :filesize',
        ];

        if (!empty($filter['component'])) {
            $where[] = 'f.component = :component';
        }

        if (!empty($filter['name_like'])) {
            // Use sql_like() rather than a bare LIKE: it is case-insensitive on every
            // engine, where plain LIKE matches case-sensitively on PostgreSQL but not MySQL.
            $where[] = $this->db->sql_like('f.filename', ':name_like', false);
        }

        if (!empty($filter['user_like'])) {
            // Use database-agnostic concatenation via Moodle's sql_concat.
            $fullname1 = $this->db->sql_concat('u.firstname', "' '", 'u.lastname');
            $fullname2 = $this->db->sql_concat('u.lastname', "' '", 'u.firstname');
            $where[] = '('
                . $this->db->sql_like($fullname1, ':user_like', false)
                . ' OR ' . $this->db->sql_like($fullname2, ':user_like_reversed', false)
                . ' OR ' . $this->db->sql_like('f.author', ':user_like_author', false)
                . ')';
        }

        if (!empty($filter['user_deleted'])) {
            $where[] = 'u.deleted = 1';
        }

        return $where;
    }

    /**
     * Build SQL query for file search.
     *
     * @param array $filter Filter criteria
     * @param bool $count Whether this is for counting (true) or selecting records (false)
     * @return string SQL query
     */
    private function get_search_sql(array $filter, bool $count = false): string {
        $parts = $this->get_sql_parts($filter);
        $where = [$parts['where']];

        if ($count) {
            return sprintf(
                'SELECT COUNT(f.id) FROM %s WHERE %s',
                $parts['from'],
                $parts['where']
            );
        }

        // Records that share a content hash are still separate records, each listed and each
        // deleted on its own, so they are no longer collapsed. Collapsing them also selected
        // columns that were neither grouped nor aggregated, which PostgreSQL rejects outright.
        //
        // The ORDER BY is what makes paging stable; without it the same row can appear on two
        // pages, or on none. Bounds are applied by the caller through the DML layer rather
        // than a literal LIMIT, which is not portable.
        //
        // Nothing indexes filesize on its own, so the unfiltered report scans and then sorts.
        // That is a deliberate trade: correct paging is worth more than the early exit an
        // unordered query allowed, and with a component filter set the component_filesize
        // index serves this ordering. Do not remove it to save the sort.
        return sprintf(
            'SELECT %s FROM %s WHERE %s ORDER BY %s',
            $parts['fields'],
            $parts['from'],
            $parts['where'],
            'f.filesize DESC, f.id ASC'
        );
    }
}
