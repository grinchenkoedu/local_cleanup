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
use local_cleanup\step_result;

/**
 * Tests for the grades clean-up step.
 *
 * This step deletes grade data, so each test asserts what survives as well as what goes.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_cleanup\step\grades_cleanup
 */
final class grades_cleanup_test extends advanced_testcase {
    /**
     * Days of grade history to keep, for these tests.
     */
    const KEEP_DAYS = 500;

    /**
     * An id that belongs to no record, standing in for something already deleted.
     */
    const MISSING_ID = 987654321;

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
     * A grade item whose course is gone goes; one belonging to a real course stays.
     *
     * @return void
     */
    public function test_grade_items_for_deleted_courses_are_removed(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $kept = $this->create_grade_item($course->id);
        $orphan = $this->create_grade_item(self::MISSING_ID);

        $this->run_cleanup();

        $this->assertFalse(
            $DB->record_exists('grade_items', ['id' => $orphan]),
            'A grade item for a course that no longer exists should be removed.'
        );
        $this->assertTrue(
            $DB->record_exists('grade_items', ['id' => $kept]),
            'A grade item belonging to a real course must be kept.'
        );
    }

    /**
     * A grade with no grade item goes; one attached to a real item and user stays.
     *
     * @return void
     */
    public function test_orphaned_grades_are_removed(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $itemid = $this->create_grade_item($course->id);

        $kept = $DB->insert_record('grade_grades', (object)[
            'itemid' => $itemid,
            'userid' => $user->id,
        ]);
        $orphan = $DB->insert_record('grade_grades', (object)[
            'itemid' => self::MISSING_ID,
            'userid' => $user->id,
        ]);

        $this->run_cleanup();

        $this->assertFalse(
            $DB->record_exists('grade_grades', ['id' => $orphan]),
            'A grade with no grade item behind it should be removed.'
        );
        $this->assertTrue(
            $DB->record_exists('grade_grades', ['id' => $kept]),
            'A grade attached to a real item and a real user must be kept.'
        );
    }

    /**
     * A grade for a user that no longer exists is removed.
     *
     * @return void
     */
    public function test_grades_for_deleted_users_are_removed(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $itemid = $this->create_grade_item($course->id);

        $orphan = $DB->insert_record('grade_grades', (object)[
            'itemid' => $itemid,
            'userid' => self::MISSING_ID,
        ]);

        $this->run_cleanup();

        $this->assertFalse(
            $DB->record_exists('grade_grades', ['id' => $orphan]),
            'A grade belonging to a user row that is gone should be removed.'
        );
    }

    /**
     * History past its lifetime goes; recent history stays.
     *
     * @return void
     */
    public function test_only_outdated_history_is_removed(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $itemid = $this->create_grade_item($course->id);

        $old = $this->create_history($itemid, $user->id, time() - (self::KEEP_DAYS + 1) * DAYSECS);
        $recent = $this->create_history($itemid, $user->id, time());

        $this->run_cleanup();

        $this->assertFalse(
            $DB->record_exists('grade_grades_history', ['id' => $old]),
            'History past its lifetime should be removed.'
        );
        $this->assertTrue(
            $DB->record_exists('grade_grades_history', ['id' => $recent]),
            'History inside its lifetime must be kept.'
        );
    }

    /**
     * A dry run counts the same records and removes none of them.
     *
     * @return void
     */
    public function test_report_counts_without_deleting(): void {
        global $DB;

        $this->resetAfterTest();

        $orphan = $this->create_grade_item(self::MISSING_ID);
        $before = $DB->count_records('grade_items');

        $result = $this->run_report();

        $this->assertGreaterThanOrEqual(1, $result->get_records());
        $this->assertTrue($DB->record_exists('grade_items', ['id' => $orphan]));
        $this->assertSame($before, $DB->count_records('grade_items'), 'A dry run must write nothing.');
    }

    /**
     * Run the step under test.
     *
     * @return spy_output The captured output
     */
    private function run_cleanup(): spy_output {
        global $DB;

        $output = new spy_output();
        $step = new grades_cleanup($DB, self::KEEP_DAYS);
        $step->execute($output);

        return $output;
    }

    /**
     * Report on the step under test.
     *
     * @return step_result What would be removed
     */
    private function run_report(): step_result {
        global $DB;

        return (new grades_cleanup($DB, self::KEEP_DAYS))->report(new spy_output());
    }

    /**
     * Create a manual grade item.
     *
     * The type matters: an item of type "mod" is also checked against course_modules, which
     * would remove these fixtures for an unrelated reason.
     *
     * @param int $courseid Course the item belongs to
     * @return int Id of the inserted item
     */
    private function create_grade_item(int $courseid): int {
        global $DB;

        return $DB->insert_record('grade_items', (object)[
            'courseid' => $courseid,
            'itemtype' => 'manual',
            'itemname' => 'Test item ' . random_string(8),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Create a grade history record.
     *
     * @param int $itemid Grade item it belongs to
     * @param int $userid User it belongs to
     * @param int $timemodified When it was recorded
     * @return int Id of the inserted record
     */
    private function create_history(int $itemid, int $userid, int $timemodified): int {
        global $DB;

        return $DB->insert_record('grade_grades_history', (object)[
            'oldid' => 1,
            'itemid' => $itemid,
            'userid' => $userid,
            'source' => 'test',
            'timemodified' => $timemodified,
        ]);
    }
}
