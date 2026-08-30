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

use advanced_testcase;
use local_cleanup\output\spy_output;

/**
 * Tests for the stuck course modules clean-up step.
 *
 * This step forces through deletions that Moodle has already failed to complete, so what it
 * considers in scope matters more than what it removes: a task that is merely slow, or one
 * belonging to another subsystem, must be left for Moodle to finish on its own.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_cleanup\step\course_modules_cleanup
 */
final class course_modules_cleanup_test extends advanced_testcase {
    /**
     * Days a failing task is left alone, for these tests.
     */
    const KEEP_DAYS = 7;

    /**
     * The task class this step is allowed to force through.
     */
    const REMOVAL_TASK = '\core_course\task\course_delete_modules';

    /**
     * Load the output spy, which lives outside the autoloaded class directory.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        require_once(__DIR__ . '/../fixtures/spy_output.php');

        parent::setUpBeforeClass();
    }

    /**
     * A module whose course row has gone is reported as orphaned.
     *
     * @return void
     */
    public function test_a_module_whose_course_is_gone_is_counted(): void {
        $this->resetAfterTest();

        $this->create_orphaned_module();

        $output = $this->run_report();

        $this->assertTrue(
            $output->contains('1 course module(s) belong to a course that is gone.'),
            'The orphan should be counted.'
        );
    }

    /**
     * A module in a course that still exists is not an orphan.
     *
     * @return void
     */
    public function test_a_healthy_module_is_not_counted(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $output = $this->run_report();

        $this->assertTrue(
            $output->contains('0 course module(s) belong to a course that is gone.'),
            'A module with a live course must not be reported.'
        );
    }

    /**
     * Modules named by a long-failing removal task are counted.
     *
     * @return void
     */
    public function test_modules_behind_a_failed_task_are_counted(): void {
        $this->resetAfterTest();

        $this->create_task([101, 102]);

        $output = $this->run_report();

        $this->assertTrue(
            $output->contains('2 course module(s) sit behind a failed removal task.'),
            'Both modules named in the task should be counted.'
        );
    }

    /**
     * A task that has not failed is left for Moodle to run.
     *
     * @return void
     */
    public function test_a_task_that_has_not_failed_is_ignored(): void {
        $this->resetAfterTest();

        $this->create_task([101], ['faildelay' => 0]);

        $this->assert_no_stuck_modules_reported();
    }

    /**
     * A task that started inside the grace period is left alone.
     *
     * @return void
     */
    public function test_a_recently_started_task_is_ignored(): void {
        $this->resetAfterTest();

        $this->create_task([101], ['timestarted' => time() - DAYSECS]);

        $this->assert_no_stuck_modules_reported();
    }

    /**
     * A failing task belonging to another subsystem is not this step's business.
     *
     * @return void
     */
    public function test_another_task_class_is_ignored(): void {
        $this->resetAfterTest();

        $this->create_task([101], ['classname' => '\core\task\asynchronous_backup_task']);

        $this->assert_no_stuck_modules_reported();
    }

    /**
     * A dry run counts the work and does none of it.
     *
     * @return void
     */
    public function test_report_counts_without_deleting(): void {
        global $DB;

        $this->resetAfterTest();

        $cmid = $this->create_orphaned_module();
        $taskid = $this->create_task([$cmid]);

        $result = (new course_modules_cleanup($DB, self::KEEP_DAYS))->report(new spy_output());

        $this->assertSame(2, $result->get_records(), 'One orphan plus one stuck module.');
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $cmid]), 'A dry run must remove nothing.');
        $this->assertTrue($DB->record_exists('task_adhoc', ['id' => $taskid]), 'A dry run must clear no task.');
    }

    /**
     * A module behind a stuck task is deleted and the task is cleared with it.
     *
     * @return void
     */
    public function test_a_stuck_module_is_deleted_and_its_task_cleared(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $taskid = $this->create_task([$module->cmid]);

        $result = (new course_modules_cleanup($DB, self::KEEP_DAYS))->execute(new spy_output());

        $this->assertSame(1, $result->get_records());
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $module->cmid]), 'The module should be gone.');
        $this->assertFalse(
            $DB->record_exists('task_adhoc', ['id' => $taskid]),
            'The task is cleared once every module it named has been dealt with.'
        );
    }

    /**
     * A healthy module that no task names is left where it is.
     *
     * @return void
     */
    public function test_a_module_not_behind_a_task_is_left_alone(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $result = (new course_modules_cleanup($DB, self::KEEP_DAYS))->execute(new spy_output());

        $this->assertSame(0, $result->get_records());
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $module->cmid]));
    }

    /**
     * Run the dry run and hand back what it wrote.
     *
     * @return spy_output The captured output
     */
    private function run_report(): spy_output {
        global $DB;

        $output = new spy_output();
        (new course_modules_cleanup($DB, self::KEEP_DAYS))->report($output);

        return $output;
    }

    /**
     * Assert the dry run found nothing sitting behind a failed task.
     *
     * @return void
     */
    private function assert_no_stuck_modules_reported(): void {
        $this->assertTrue(
            $this->run_report()->contains('0 course module(s) sit behind a failed removal task.'),
            'The task is out of scope and must not be counted.'
        );
    }

    /**
     * Create a module and then remove its course row, leaving the module orphaned.
     *
     * @return int The course module id
     */
    private function create_orphaned_module(): int {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        // Drop the course row directly. Deleting the course properly would take the module
        // with it, which is the situation this step exists because Moodle did not reach.
        $DB->delete_records('course', ['id' => $course->id]);

        return (int)$module->cmid;
    }

    /**
     * Record an adhoc removal task that has been failing since before the grace period.
     *
     * @param int[] $cmids Course module ids the task was meant to remove
     * @param array $overrides Field values to override the defaults with
     * @return int The task id
     */
    private function create_task(array $cmids, array $overrides = []): int {
        global $DB;

        $cms = [];

        foreach ($cmids as $cmid) {
            $cms[] = ['id' => $cmid];
        }

        return (int)$DB->insert_record('task_adhoc', (object)($overrides + [
            'component' => 'core',
            'classname' => self::REMOVAL_TASK,
            'nextruntime' => time(),
            'faildelay' => 300,
            'customdata' => json_encode(['cms' => $cms]),
            'blocking' => 0,
            'timecreated' => time() - (self::KEEP_DAYS + 2) * DAYSECS,
            'timestarted' => time() - (self::KEEP_DAYS + 1) * DAYSECS,
        ]));
    }
}
