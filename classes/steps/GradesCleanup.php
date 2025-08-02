<?php

namespace local_cleanup\steps;

use local_cleanup\output\OutputInterface;
use moodle_database;

class GradesCleanup extends AbstractCleanupStep
{
    const DEFAULT_LIFETIME_DAYS = 500;

    private int $daysToKeep;

    public function __construct(moodle_database $db, int $daysToKeep = self::DEFAULT_LIFETIME_DAYS)
    {
        parent::__construct($db);

        $this->daysToKeep = $daysToKeep;
    }

    public function cleanUp(OutputInterface $output)
    {
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

    private function cleanupGradeItemsForDeletedCourses(OutputInterface $output)
    {
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

    private function cleanupGradeItemsForDeletedModules(OutputInterface $output)
    {
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

    private function cleanupOrphanedGradeGrades(OutputInterface $output)
    {
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

    private function cleanupGradeGradesForDeletedUsers(OutputInterface $output)
    {
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

    private function cleanupGradeCategoriesForDeletedCourses(OutputInterface $output)
    {
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

    private function cleanupGradeOutcomesCoursesForDeletedCourses(OutputInterface $output)
    {
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

    private function cleanupGradeGradesHistory(OutputInterface $output)
    {
        $cutoffDate = time() - ($this->daysToKeep * 24 * 60 * 60);

        $sql = "SELECT ggh.id
                FROM {grade_grades_history} ggh
                LEFT JOIN {grade_items} gi ON ggh.itemid = gi.id
                WHERE gi.id IS NULL OR ggh.timemodified < :cutoffdate";

        $this->processRecordsInBatches(
            'grade_grades_history',
            'ggh',
            $sql,
            ['cutoffdate' => $cutoffDate],
            sprintf(
                'Checking for grade grades history with no corresponding grade items or older than %d days...',
                $this->daysToKeep
            ),
            $output
        );
    }
}
