<?php

namespace local_cleanup;

use dml_exception;
use moodle_database;
use moodle_recordset;
use core_user\fields;

class finder
{
    const LIMIT_DEFAULT = 50;

    private moodle_database $db;

    public function __construct(moodle_database $db)
    {
        $this->db = $db;
    }

    public function find(int $limit = self::LIMIT_DEFAULT, int $offset = 0, array $filter = []): moodle_recordset
    {
        return $this->db->get_recordset_sql(
            $this->get_search_sql($filter, false, $limit, $offset),
            $this->get_search_values($filter)
        );
    }

    public function count(array $filter = []): int
    {
        return (int)$this->db->get_field_sql(
            $this->get_search_sql($filter, true),
            $this->get_search_values($filter)
        );
    }

    /**
     * @param string $component Component name
     * @param string|null $until Date string for filtering (e.g., '-1 year')
     * @param bool $newer_than If true, get files newer than $until; if false, get files older than $until
     * @param string|null $from Date string for filtering (e.g., '-2 years')
     * @return object {count: int, size: int (bytes)}
     *
     * @throws dml_exception
     */
    public function stats(string $component, string $until = null, bool $newer_than = false, string $from = null)
    {
        $sql = '
            SELECT 
                COUNT(f.id) as `count`,
                COALESCE(SUM(f.filesize), 0) as `size`
            FROM {files} f
            WHERE f.component = ?
        ';

        // For backup component, use timemodified instead of timecreated
        $timeField = ($component === 'backup') ? 'f.timemodified' : 'f.timecreated';

        // If both from and until are provided, get files in the specific time period
        if ($from !== null && $until !== null) {
            $from_timestamp = strtotime($from);
            $until_timestamp = strtotime($until);
            $sql .= " AND $timeField >= $from_timestamp AND $timeField < $until_timestamp";

        } else if ($until !== null) {
            $operator = $newer_than ? '>' : '<';
            $sql .= " AND $timeField $operator " . strtotime($until);
        }

        return $this->db->get_record_sql($sql, [$component]);
    }

    private function get_search_values(array $filter): array
    {
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

    private function get_search_sql(
        array $filter, bool
        $count = false,
        int $limit = self::LIMIT_DEFAULT,
        $offset = 0
    ): string {
        $where = [
            sprintf('f.filesize > %d', ($filter['filesize'] ?? 0) * 1024 * 1024)
        ];

        if (!empty($filter['component'])) {
            $where[] = 'f.component = :component';
        }

        if (!empty($filter['name_like'])) {
            $where[] = 'f.filename LIKE :name_like';
        }

        if (!empty($filter['user_like'])) {
            $where[] = "(CONCAT(u.firstname, ' ', u.lastname) LIKE :user_like 
                      OR CONCAT(u.lastname, ' ', u.firstname) LIKE :user_like
                      OR f.author LIKE :user_like)";
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

        $userFields = fields::for_name()
            ->get_sql('u', false, '', '', false)
            ->selects;

        return sprintf(
            'SELECT %s FROM {files} f LEFT JOIN {user} u ON f.userid = u.id WHERE %s GROUP BY f.contenthash %s',
            'f.*, u.deleted as user_deleted, ' . $userFields,
            implode(' AND ', $where),
            $offset > 0 ? sprintf('LIMIT %d, %d', $offset, $limit) : sprintf('LIMIT %d', $limit)
        );
    }
}
