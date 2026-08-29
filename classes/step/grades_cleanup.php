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
     * Execute the cleanup step.
     *
     * Runs all grade cleanup operations in sequence.
     *
     * @param output_interface $output Output handler for logging
     * @return void
     */
    public function cleanup(output_interface $output) {
        $output->write_line('Starting grades cleanup...');

        // 1. Clean up grade items tied to deleted courses.
        $this->cleanup_grade_items_for_deleted_courses($output);

        // 2. Clean up grade items for modules that no longer exist.
        $this->cleanup_grade_items_for_deleted_modules($output);

        // 3. Clean up grade grades with no corresponding grade items.
        $this->cleanup_orphaned_grade_grades($output);

        // 4. Clean up grade grades for deleted users.
        $this->cleanup_grade_grades_for_deleted_users($output);

        // 5. Clean up grade categories tied to deleted courses.
        $this->cleanup_grade_categories_for_deleted_courses($output);

        // 6. Clean up grade outcomes courses tied to deleted courses.
        $this->cleanup_grade_outcomes_courses_for_deleted_courses($output);

        // 7. Clean up grade grades history with no corresponding grade items.
        $this->cleanup_grade_grades_history($output);

        $output->write_line('Grades cleanup completed.');
    }

    /**
     * Clean up grade items tied to deleted courses.
     *
     * @param output_interface $output Output handler for logging
     * @return void
     */
    private function cleanup_grade_items_for_deleted_courses(output_interface $output) {
        $sql = "SELECT gi.id
                FROM {grade_items} gi
                LEFT JOIN {course} c ON gi.courseid = c.id
                WHERE gi.courseid IS NOT NULL
                AND c.id IS NULL";

        $this->process_records_in_batches(
            'grade_items',
            'gi',
            $sql,
            [],
            'Checking for grade items tied to deleted courses...',
            $output
        );
    }

    /**
     * Clean up grade items for modules that no longer exist.
     *
     * @param output_interface $output Output handler for logging
     * @return void
     */
    private function cleanup_grade_items_for_deleted_modules(output_interface $output) {
        $sql = "SELECT gi.id
                FROM {grade_items} gi
                WHERE gi.itemtype = 'mod'
                AND NOT EXISTS (
                    SELECT 1
                    FROM {course_modules} cm
                    WHERE cm.course = gi.courseid AND cm.instance = gi.iteminstance
                )";

        $this->process_records_in_batches(
            'grade_items',
            'gi',
            $sql,
            [],
            'Checking for grade items tied to deleted modules...',
            $output
        );
    }

    /**
     * Clean up grade grades with no corresponding grade items.
     *
     * @param output_interface $output Output handler for logging
     * @return void
     */
    private function cleanup_orphaned_grade_grades(output_interface $output) {
        $sql = "SELECT gg.id
                FROM {grade_grades} gg
                LEFT JOIN {grade_items} gi ON gi.id = gg.itemid
                WHERE gi.id IS NULL";

        $this->process_records_in_batches(
            'grade_grades',
            'gg',
            $sql,
            [],
            'Checking for grade grades with no corresponding grade items...',
            $output
        );
    }

    /**
     * Clean up grade grades for deleted users.
     *
     * @param output_interface $output Output handler for logging
     * @return void
     */
    private function cleanup_grade_grades_for_deleted_users(output_interface $output) {
        $sql = "SELECT gg.id
                FROM {grade_grades} gg
                LEFT JOIN {user} u ON gg.userid = u.id
                WHERE u.id IS NULL";

        $this->process_records_in_batches(
            'grade_grades',
            'gg',
            $sql,
            [],
            'Checking for grade grades tied to deleted users...',
            $output
        );
    }

    /**
     * Clean up grade categories tied to deleted courses.
     *
     * @param output_interface $output Output handler for logging
     * @return void
     */
    private function cleanup_grade_categories_for_deleted_courses(output_interface $output) {
        $sql = "SELECT gc.id
                FROM {grade_categories} gc
                LEFT JOIN {course} c ON gc.courseid = c.id
                WHERE c.id IS NULL";

        $this->process_records_in_batches(
            'grade_categories',
            'gc',
            $sql,
            [],
            'Checking for grade categories tied to deleted courses...',
            $output
        );
    }

    /**
     * Clean up grade outcomes courses tied to deleted courses.
     *
     * @param output_interface $output Output handler for logging
     * @return void
     */
    private function cleanup_grade_outcomes_courses_for_deleted_courses(output_interface $output) {
        $sql = "SELECT goc.id
                FROM {grade_outcomes_courses} goc
                LEFT JOIN {course} c ON goc.courseid = c.id
                WHERE c.id IS NULL";

        $this->process_records_in_batches(
            'grade_outcomes_courses',
            'goc',
            $sql,
            [],
            'Checking for grade outcomes courses tied to deleted courses...',
            $output
        );
    }

    /**
     * Clean up grade grades history with no corresponding grade items or older than the configured days to keep.
     *
     * @param output_interface $output Output handler for logging
     * @return void
     */
    private function cleanup_grade_grades_history(output_interface $output) {
        $cutoffdate = time() - ($this->daystokeep * 24 * 60 * 60);

        $sql = "SELECT ggh.id
                FROM {grade_grades_history} ggh
                LEFT JOIN {grade_items} gi ON ggh.itemid = gi.id
                WHERE gi.id IS NULL OR ggh.timemodified < :cutoffdate";

        $this->process_records_in_batches(
            'grade_grades_history',
            'ggh',
            $sql,
            ['cutoffdate' => $cutoffdate],
            sprintf(
                'Checking for grade grades history with no corresponding grade items or older than %d days...',
                $this->daystokeep
            ),
            $output
        );
    }
}
