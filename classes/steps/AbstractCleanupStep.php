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

namespace local_cleanup\steps;

use local_cleanup\output\OutputInterface;
use moodle_database;

/**
 * Abstract base class for cleanup steps.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class AbstractCleanupStep implements CleanupStepInterface {

    /**
     * Database connection.
     *
     * @var moodle_database
     */
    protected $db;

    /**
     * Maximum number of records to process in a single batch.
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
     * Execute the cleanup step.
     *
     * @param OutputInterface $output Output handler for logging
     * @return void
     */
    abstract public function cleanup(OutputInterface $output);

    /**
     * Process records in batches and delete them
     *
     * @param string $table The database table name
     * @param string $alias The table alias used in the SQL query
     * @param string $sql The SQL query to find records to delete
     * @param array $params The parameters for the SQL query
     * @param string $message The message to display when checking for records
     * @param OutputInterface $output The output interface for logging
     */
    protected function processrecordsinbatches(
        $table,
        $alias,
        $sql,
        $params,
        $message,
        OutputInterface $output
    ): void {
        $output->writeLine(sprintf('Cleaning %s: %s', $table, $message));

        $limit = self::BATCH_SIZE * 100;
        $totaldeleted = 0;
        $batchnumber = 0;
        $lastid = 0;

        do {
            if ($batchnumber > 0) {
                $output->writeLine(
                    sprintf(
                        'Cleaning %s: Loading batch %d...',
                        $table,
                        $batchnumber + 1
                    )
                );
            }

            $boundedsql = sprintf(
                '%s AND %s.id > :lastid ORDER BY %s.id ASC LIMIT %d',
                $sql,
                $alias,
                $alias,
                $limit
            );
            $boundedparams = array_merge($params, ['lastid' => $lastid]);

            $starttime = microtime(true);

            $ids = $this->db->get_fieldset_sql($boundedsql, $boundedparams);
            $lastid = end($ids);
            $count = count($ids);
            $batchnumber++;

            if ($count > 0) {
                $output->write('Deleting..');

                while (!empty($ids)) {
                    $batchids = array_splice($ids, 0, self::BATCH_SIZE);
                    $batchcount = count($batchids);
                    $totaldeleted += $batchcount;

                    $this->db->delete_records_list($table, 'id', $batchids);
                    $output->write('.');
                }

                $endtime = microtime(true);
                $elapsedseconds = $endtime - $starttime;
                $output->writeLine(
                    sprintf(
                        'OK (took %02d:%02d)',
                        floor($elapsedseconds / 60),
                        floor($elapsedseconds % 60)
                    )
                );
            }
        } while ($count === $limit);

        if ($totaldeleted === 0) {
            $output->writeLine('None found.');

            return;
        }

        $output->writeLine("Total records deleted: $totaldeleted. Done.");
    }
}
