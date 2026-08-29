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

use advanced_testcase;
use context_system;
use local_cleanup\output\spy_output;

/**
 * Tests for the logs clean-up step.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_cleanup\steps\LogsCleanup
 */
final class logs_cleanup_test extends advanced_testcase {
    /**
     * Days of logs to keep, for these tests.
     */
    const KEEP_DAYS = 500;

    /**
     * A context id that does not exist, standing in for a deleted context.
     */
    const MISSING_CONTEXT = 987654321;

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
     * An entry older than the cut-off is removed and a recent one is kept.
     *
     * @return void
     */
    public function test_only_outdated_entries_are_removed(): void {
        global $DB;

        $this->resetAfterTest();

        $old = $this->create_log(time() - (self::KEEP_DAYS + 1) * DAYSECS);
        $recent = $this->create_log(time());

        $this->run_cleanup();

        $this->assertFalse(
            $DB->record_exists('logstore_standard_log', ['id' => $old]),
            'An entry past its lifetime should be removed.'
        );
        $this->assertTrue(
            $DB->record_exists('logstore_standard_log', ['id' => $recent]),
            'An entry inside its lifetime must be kept.'
        );
    }

    /**
     * An entry whose context no longer exists is removed regardless of its age.
     *
     * @return void
     */
    public function test_entry_with_a_deleted_context_is_removed(): void {
        global $DB;

        $this->resetAfterTest();

        $orphan = $this->create_log(time(), self::MISSING_CONTEXT);

        $this->run_cleanup();

        $this->assertFalse(
            $DB->record_exists('logstore_standard_log', ['id' => $orphan]),
            'A log entry pointing at a context that is gone should be removed.'
        );
    }

    /**
     * The analytics log table is optional, and its absence is reported rather than fatal.
     *
     * @return void
     */
    public function test_absent_analytics_table_is_skipped(): void {
        global $DB;

        $this->resetAfterTest();

        if ($DB->get_manager()->table_exists('logstore_lanalytics_log')) {
            $this->markTestSkipped('This site has the analytics log table installed.');
        }

        $output = $this->run_cleanup();

        $this->assertTrue(
            $output->contains('Skipping cleanup of logstore_lanalytics_log'),
            'The step should say it skipped the optional table.'
        );
    }

    /**
     * Run the step under test.
     *
     * @return spy_output The captured output
     */
    private function run_cleanup(): spy_output {
        global $DB;

        $output = new spy_output();
        $step = new LogsCleanup($DB, self::KEEP_DAYS);
        $step->cleanup($output);

        return $output;
    }

    /**
     * Insert a log entry directly, which is enough to exercise the deletion query.
     *
     * @param int $timecreated When the entry was recorded
     * @param int|null $contextid Context to attribute it to, defaulting to the system context
     * @return int Id of the inserted entry
     */
    private function create_log(int $timecreated, ?int $contextid = null): int {
        global $DB;

        return $DB->insert_record('logstore_standard_log', (object)[
            'eventname' => '\core\event\course_viewed',
            'component' => 'core',
            'action' => 'viewed',
            'target' => 'course',
            'crud' => 'r',
            'edulevel' => 0,
            'contextid' => $contextid ?? context_system::instance()->id,
            'contextlevel' => CONTEXT_SYSTEM,
            'contextinstanceid' => 0,
            'userid' => 0,
            'anonymous' => 0,
            'timecreated' => $timecreated,
        ]);
    }
}
