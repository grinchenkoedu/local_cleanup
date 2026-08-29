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
     * Name this step.
     *
     * @return string Short human-readable name
     */
    public function get_name(): string {
        return 'Logs';
    }

    /**
     * Obsolete or outdated entries, in whichever log tables this site has.
     *
     * @return array[] Candidate sets
     */
    protected function get_candidates(): array {
        $candidates = [
            $this->log_candidate('logstore_standard_log'),
        ];

        if ($this->db->get_manager()->table_exists('logstore_lanalytics_log')) {
            $candidates[] = $this->log_candidate('logstore_lanalytics_log');
        }

        return $candidates;
    }

    /**
     * Build the candidate set for one log table.
     *
     * Both tables have the same shape, so the same query serves either.
     *
     * @param string $table Log table name
     * @return array Candidate set
     */
    private function log_candidate(string $table): array {
        return [
            'table' => $table,
            'sql' => "SELECT l.id
                        FROM {" . $table . "} l
                   LEFT JOIN {context} ctx ON ctx.id = l.contextid
                       WHERE ctx.id IS NULL
                          OR l.timecreated < :cutoffdate",
            'params' => ['cutoffdate' => $this->cutoffdate],
            'message' => sprintf('obsolete, or older than %d days', $this->cutoffdays),
        ];
    }
}
