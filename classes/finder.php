<?php

namespace local_cleanup;

use moodle_database;
use moodle_recordset;
use dml_exception;

class finder
{
    const LIMIT_DEFAULT = 50;

    /**
     * @var moodle_database
     */
    private $db;

    /**
     * @var int
     */
    private $user_id;

    /**
     * @var bool
     */
    private $admin_mode;

    /**
     * @param moodle_database $db
     * @param int $user_id
     * @param false $admin_mode
     */
    public function __construct(moodle_database $db, $user_id, $admin_mode = false)
    {
        $this->db = $db;
        $this->user_id = $user_id;
        $this->admin_mode = $admin_mode;
    }

    /**
     * @param int $limit
     * @param int $offset
     * @param array $filter
     *
     * @return moodle_recordset
     *
     * @throws dml_exception
     */
    public function find($limit = self::LIMIT_DEFAULT, $offset = 0, array $filter = [])
    {
        return $this->db->get_recordset_sql(
            $this->get_search_sql($filter, false, $limit, $offset),
            $this->get_search_values($filter)
        );
    }

    /**
     * @param array $filter
     *
     * @return int
     *
     * @throws dml_exception
     */
    public function count(array $filter = [])
    {
        return (int)$this->db->get_field_sql(
            $this->get_search_sql($filter, true),
            $this->get_search_values($filter)
        );
    }

    /**
     * @param array $filter
     *
     * @return array
     */
    private function get_search_values(array $filter)
    {
        $values = [];

        if (!empty($filter['name_like'])) {
            $values['name_like'] = '%' . $filter['name_like'] . '%';
        }

        if (!empty($filter['user_like'])) {
            $values['user_like'] = '%' . $filter['user_like'] . '%';
        }

        return $values;
    }

    /**
     * @param array $filter
     * @param int $limit
     * @param int $offset
     * @param false $count
     *
     * @return string
     *
     * @throws dml_exception
     */
    private function get_search_sql(array $filter, $count = false, $limit = self::LIMIT_DEFAULT, $offset = 0)
    {
        $where = [
            'f.filesize != 0'
        ];

        if (!empty($filter['name_like'])) {
            $where[] = 'f.filename LIKE :name_like';
        }

        if (!empty($filter['user_like'])) {
            $where[] = "(CONCAT(u.firstname, ' ', u.lastname) LIKE :user_like 
                      OR CONCAT(u.lastname, ' ', u.firstname) LIKE :user_like)";
        }

        if (!$this->admin_mode) {
            $where[] = sprintf(
                '(f.contextid IN (%s) OR f.userid = %d)',
                implode(',', $this->get_allowed_contexts()),
                $this->user_id
            );
        }

        if ($count) {
            return sprintf(
                'SELECT COUNT(f.id) FROM {files} f WHERE %s',
                implode(' AND ', $where)
            );
        }

        return sprintf(
            'SELECT %s FROM {files} f LEFT JOIN {user} u ON f.userid = u.id WHERE %s ORDER BY %s %s',
            'f.*, u.firstname, u.lastname',
            implode(' AND ', $where),
            'f.filesize DESC',
            $offset > 0 ? sprintf('LIMIT %d, %d', $offset, $limit) : sprintf('LIMIT %d', $limit)
        );
    }

    /**
     * @return string[]|int[]
     *
     * @throws dml_exception
     */
    private function get_allowed_contexts()
    {
        $ids = $this->db->get_fieldset_select(
            'role_assignments',
            'contextid',
            sprintf('userid = %d GROUP BY contextid', $this->user_id)
        );

        return count($ids) < 1 ? [0] : $ids;
    }
}
