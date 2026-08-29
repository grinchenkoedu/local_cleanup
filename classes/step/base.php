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

namespace local_cleanup\step;

use local_cleanup\output\output_interface;
use local_cleanup\step_result;
use moodle_database;

/**
 * Abstract base class for cleanup steps.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base implements step_interface {
    /**
     * Database connection.
     *
     * @var moodle_database
     */
    protected $db;

    /**
     * Maximum number of records to delete in a single statement.
     */
    const BATCH_SIZE = 999;

    /**
     * Constructor.
     *
     * @param moodle_database $db Database connection
     */
    public function __construct(moodle_database $db) {
        $this->db = $db;
    }

    /**
     * Describe everything this step targets.
     *
     * One definition serves both report() and execute(), so a dry run cannot describe something
     * different from what the real run removes.
     *
     * Each entry is ['table' => string, 'sql' => string, 'params' => array, 'message' => string]
     * where the query selects nothing but the id column of the rows to remove.
     *
     * @return array[] Candidate sets
     */
    abstract protected function get_candidates(): array;

    /**
     * Count what would be removed, touching nothing.
     *
     * @param output_interface $output Output handler for progress
     * @return step_result What would be removed
     */
    public function report(output_interface $output): step_result {
        $result = new step_result();

        foreach ($this->get_candidates() as $candidate) {
            $count = (int)$this->db->count_records_sql(
                sprintf('SELECT COUNT(1) FROM (%s) candidates', $candidate['sql']),
                $candidate['params']
            );

            $result->add($count);
            $output->write_line(sprintf(
                '%s: %d record(s) would be deleted (%s)',
                $candidate['table'],
                $count,
                $candidate['message']
            ));
        }

        return $result;
    }

    /**
     * Remove it.
     *
     * @param output_interface $output Output handler for progress
     * @return step_result What was removed
     */
    public function execute(output_interface $output): step_result {
        $result = new step_result();

        foreach ($this->get_candidates() as $candidate) {
            $result->add($this->process_records_in_batches(
                $candidate['table'],
                $candidate['sql'],
                $candidate['params'],
                $candidate['message'],
                $output
            ));
        }

        return $result;
    }

    /**
     * Delete the rows a query selects, a batch at a time.
     *
     * The candidate query is wrapped in a derived table rather than having conditions appended
     * to it. Appending "AND alias.id > :lastid" to a WHERE clause containing a top-level OR
     * binds only to the last branch, so the batch bound silently did not apply to the rest.
     *
     * @param string $table The table to delete from
     * @param string $sql Query selecting the ids to remove
     * @param array $params Parameters for that query
     * @param string $message What is being cleaned, for the log
     * @param output_interface $output Output handler for progress
     * @return int Number of records deleted
     */
    protected function process_records_in_batches(
        string $table,
        string $sql,
        array $params,
        string $message,
        output_interface $output
    ): int {
        $output->write_line(sprintf('Cleaning %s: %s', $table, $message));

        $pagesize = self::BATCH_SIZE * 100;
        $bounded = sprintf(
            'SELECT candidates.id FROM (%s) candidates WHERE candidates.id > :lastid ORDER BY candidates.id ASC',
            $sql
        );

        $totaldeleted = 0;
        $lastid = 0;

        do {
            $starttime = microtime(true);
            $ids = array_keys($this->db->get_records_sql(
                $bounded,
                array_merge($params, ['lastid' => $lastid]),
                0,
                $pagesize
            ));
            $found = count($ids);

            if ($found === 0) {
                break;
            }

            $lastid = end($ids);
            $output->write('Deleting..');

            while (!empty($ids)) {
                $batch = array_splice($ids, 0, self::BATCH_SIZE);
                $totaldeleted += count($batch);

                $this->db->delete_records_list($table, 'id', $batch);
                $output->write('.');
            }

            // The microtime() call gives a float, and % is an integer operation. Leaving PHP to
            // convert implicitly is deprecated from 8.1 when precision is lost, which is every
            // batch not taking a whole number of seconds.
            $elapsedseconds = (int)round(microtime(true) - $starttime);
            $output->write_line(sprintf(
                'OK (took %02d:%02d)',
                intdiv($elapsedseconds, 60),
                $elapsedseconds % 60
            ));
        } while ($found === $pagesize);

        if ($totaldeleted === 0) {
            $output->write_line('None found.');
        } else {
            $output->write_line("Total records deleted: $totaldeleted. Done.");
        }

        return $totaldeleted;
    }
}
