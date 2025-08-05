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
 * Grades cleanup step.
 *
 * Handles cleanup of orphaned grade records, including grade items, grades,
 * categories, and history records.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class GradesCleanup extends AbstractCleanupStep {

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
     * @param OutputInterface $output Output handler for logging
     * @return void
     */
    public function cleanup(OutputInterface $output) {
        $output->writeLine('Starting grades cleanup...');

        // 1. Clean up grade items tied to deleted courses.
        $this->cleanupGradeItemsForDeletedCourses($output);

        // 2. Clean up grade items for modules that no longer exist.
        $this->cleanupGradeItemsForDeletedModules($output);

        // 3. Clean up grade grades with no corresponding grade items.
        $this->cleanupOrphanedGradeGrades($output);

        // 4. Clean up grade grades for deleted users.
        $this->cleanupGradeGradesForDeletedUsers($output);

        // 5. Clean up grade categories tied to deleted courses.
        $this->cleanupGradeCategoriesForDeletedCourses($output);

        // 6. Clean up grade outcomes courses tied to deleted courses.
        $this->cleanupGradeOutcomesCoursesForDeletedCourses($output);

        // 7. Clean up grade grades history with no corresponding grade items.
        $this->cleanupGradeGradesHistory($output);

        $output->writeLine('Grades cleanup completed.');
    }

    /**
     * Clean up grade items tied to deleted courses.
     *
     * @param OutputInterface $output Output handler for logging
     * @return void
     */
    private function cleanupgradeitemsfordeletedcourses(OutputInterface $output) {
        $sql = "SELECT gi.id
                FROM {grade_items} gi
                LEFT JOIN {course} c ON gi.courseid = c.id
                WHERE gi.courseid IS NOT NULL
                AND c.id IS NULL";

        $this->processRecordsInBatches(
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
     * @param OutputInterface $output Output handler for logging
     * @return void
     */
    private function cleanupgradeitemsfordeletedmodules(OutputInterface $output) {
        $sql = "SELECT gi.id
                FROM {grade_items} gi
                WHERE gi.itemtype = 'mod'
                AND NOT EXISTS (
                    SELECT 1
                    FROM {course_modules} cm
                    WHERE cm.course = gi.courseid AND cm.instance = gi.iteminstance
                )";

        $this->processRecordsInBatches(
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
     * @param OutputInterface $output Output handler for logging
     * @return void
     */
    private function cleanuporphanedgradegrades(OutputInterface $output) {
        $sql = "SELECT gg.id
                FROM {grade_grades} gg
                LEFT JOIN {grade_items} gi ON gi.id = gg.itemid
                WHERE gi.id IS NULL";

        $this->processRecordsInBatches(
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
     * @param OutputInterface $output Output handler for logging
     * @return void
     */
    private function cleanupgradegradesfordeletedusers(OutputInterface $output) {
        $sql = "SELECT gg.id
                FROM {grade_grades} gg
                LEFT JOIN {user} u ON gg.userid = u.id
                WHERE u.id IS NULL";

        $this->processRecordsInBatches(
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
     * @param OutputInterface $output Output handler for logging
     * @return void
     */
    private function cleanupgradecategoriesfordeletedcourses(OutputInterface $output) {
        $sql = "SELECT gc.id
                FROM {grade_categories} gc
                LEFT JOIN {course} c ON gc.courseid = c.id
                WHERE c.id IS NULL";

        $this->processRecordsInBatches(
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
     * @param OutputInterface $output Output handler for logging
     * @return void
     */
    private function cleanupgradeoutcomescoursesfordeletedcourses(OutputInterface $output) {
        $sql = "SELECT goc.id
                FROM {grade_outcomes_courses} goc
                LEFT JOIN {course} c ON goc.courseid = c.id
                WHERE c.id IS NULL";

        $this->processRecordsInBatches(
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
     * @param OutputInterface $output Output handler for logging
     * @return void
     */
    private function cleanupgradegradeshistory(OutputInterface $output) {
        $cutoffdate = time() - ($this->daystokeep * 24 * 60 * 60);

        $sql = "SELECT ggh.id
                FROM {grade_grades_history} ggh
                LEFT JOIN {grade_items} gi ON ggh.itemid = gi.id
                WHERE gi.id IS NULL OR ggh.timemodified < :cutoffdate";

        $this->processRecordsInBatches(
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
