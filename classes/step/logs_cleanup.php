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
use moodle_database;

/**
 * Logs cleanup step.
 *
 * Handles cleanup of standard and analytics logs based on age.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logs_cleanup extends base {
    /**
     * Default number of days to keep logs.
     */
    const DEFAULT_LIFETIME_DAYS = 500;

    /**
     * Cutoff timestamp for log deletion.
     *
     * @var int
     */
    private $cutoffdate;

    /**
     * Number of days to keep logs.
     *
     * @var int
     */
    private $cutoffdays;

    /**
     * Constructor.
     *
     * @param moodle_database $db Database connection
     * @param int $daystokeep Number of days to keep logs
     */
    public function __construct(moodle_database $db, int $daystokeep = self::DEFAULT_LIFETIME_DAYS) {
        parent::__construct($db);

        $this->cutoffdate = time() - $daystokeep * 24 * 60 * 60;
        $this->cutoffdays = $daystokeep;
    }

    /**
     * Execute the cleanup step.
     *
     * Cleans up both standard and analytics logs.
     *
     * @param output_interface $output Output handler for logging
     * @return void
     */
    public function cleanup(output_interface $output) {
        $output->write_line('Starting logs cleanup...');

        $this->cleanup_standard_logs($output);
        $this->cleanup_lanalytics_logs($output);

        $output->write_line('Logs cleanup completed.');
    }

    /**
     * Clean up standard logs that are obsolete or older than the configured days to keep.
     *
     * @param output_interface $output Output handler for logging
     * @return void
     */
    private function cleanup_standard_logs(output_interface $output) {
        $sql = "SELECT l.id
                FROM {logstore_standard_log} l
                LEFT JOIN {context} ctx ON ctx.id = l.contextid
                WHERE ctx.id IS NULL
                      OR l.timecreated < :cutoffdate";

        $this->process_records_in_batches(
            'logstore_standard_log',
            'l',
            $sql,
            ['cutoffdate' => $this->cutoffdate],
            sprintf(
                'Checking for logs to clean up (obsolete or older than %d days)...',
                $this->cutoffdays
            ),
            $output
        );
    }

    /**
     * Clean up learning analytics logs that are obsolete or older than the configured days to keep.
     *
     * @param output_interface $output Output handler for logging
     * @return void
     */
    private function cleanup_lanalytics_logs(output_interface $output) {
        if (!$this->db->get_manager()->table_exists('logstore_lanalytics_log')) {
            $output->write_line('Skipping cleanup of logstore_lanalytics_log: table does not exist.');
            return;
        }

        $sql = "SELECT l.id
                FROM {logstore_lanalytics_log} l
                LEFT JOIN {context} ctx ON ctx.id = l.contextid
                WHERE ctx.id IS NULL
                      OR l.timecreated < :cutoffdate";

        $this->process_records_in_batches(
            'logstore_lanalytics_log',
            'l',
            $sql,
            ['cutoffdate' => $this->cutoffdate],
            sprintf(
                'Checking for logs to clean up (obsolete or older than %d days)...',
                $this->cutoffdays
            ),
            $output
        );
    }
}
