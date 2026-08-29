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
 * Grades cleanup step.
 *
 * Handles cleanup of orphaned grade records, including grade items, grades,
 * categories, and history records.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grades_cleanup extends base {
    /**
     * Default number of days to keep grade history records.
     */
    const DEFAULT_LIFETIME_DAYS = 500;

    /**
     * Number of days to keep grade history records.
     *
     * @var int
     */
    private $daystokeep;

    /**
     * Constructor.
     *
     * @param moodle_database $db Database connection
     * @param int $daystokeep Number of days to keep grade history records
     */
    public function __construct(moodle_database $db, int $daystokeep = self::DEFAULT_LIFETIME_DAYS) {
        parent::__construct($db);

        $this->daystokeep = $daystokeep;
    }

    /**
     * Name this step.
     *
     * @return string Short human-readable name
     */
    public function get_name(): string {
        return 'Grades';
    }

    /**
     * Everything this step targets, in dependency order.
     *
     * Grade items go before the grades attached to them, so the orphan sweep that follows
     * catches what the earlier sets leave behind.
     *
     * @return array[] Candidate sets
     */
    protected function get_candidates(): array {
        $cutoffdate = time() - ($this->daystokeep * DAYSECS);

        return [
            [
                'table' => 'grade_items',
                'sql' => "SELECT gi.id
                            FROM {grade_items} gi
                       LEFT JOIN {course} c ON gi.courseid = c.id
                           WHERE gi.courseid IS NOT NULL
                             AND c.id IS NULL",
                'params' => [],
                'message' => 'items belonging to a course that no longer exists',
            ],
            [
                'table' => 'grade_items',
                'sql' => "SELECT gi.id
                            FROM {grade_items} gi
                           WHERE gi.itemtype = 'mod'
                             AND NOT EXISTS (
                                 SELECT 1
                                   FROM {course_modules} cm
                                  WHERE cm.course = gi.courseid
                                    AND cm.instance = gi.iteminstance
                             )",
                'params' => [],
                'message' => 'items belonging to a module that no longer exists',
            ],
            [
                'table' => 'grade_grades',
                'sql' => "SELECT gg.id
                            FROM {grade_grades} gg
                       LEFT JOIN {grade_items} gi ON gi.id = gg.itemid
                           WHERE gi.id IS NULL",
                'params' => [],
                'message' => 'grades with no grade item behind them',
            ],
            [
                'table' => 'grade_grades',
                'sql' => "SELECT gg.id
                            FROM {grade_grades} gg
                       LEFT JOIN {user} u ON gg.userid = u.id
                           WHERE u.id IS NULL",
                'params' => [],
                'message' => 'grades belonging to a user that no longer exists',
            ],
            [
                'table' => 'grade_categories',
                'sql' => "SELECT gc.id
                            FROM {grade_categories} gc
                       LEFT JOIN {course} c ON gc.courseid = c.id
                           WHERE c.id IS NULL",
                'params' => [],
                'message' => 'categories belonging to a course that no longer exists',
            ],
            [
                'table' => 'grade_outcomes_courses',
                'sql' => "SELECT goc.id
                            FROM {grade_outcomes_courses} goc
                       LEFT JOIN {course} c ON goc.courseid = c.id
                           WHERE c.id IS NULL",
                'params' => [],
                'message' => 'outcome links to a course that no longer exists',
            ],
            [
                'table' => 'grade_grades_history',
                'sql' => "SELECT ggh.id
                            FROM {grade_grades_history} ggh
                       LEFT JOIN {grade_items} gi ON ggh.itemid = gi.id
                           WHERE gi.id IS NULL
                              OR ggh.timemodified < :cutoffdate",
                'params' => ['cutoffdate' => $cutoffdate],
                'message' => sprintf('history orphaned, or older than %d days', $this->daystokeep),
            ],
        ];
    }
}
