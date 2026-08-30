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
use context_system;
use local_cleanup\output\spy_output;
use local_cleanup\step_result;
use stored_file;

/**
 * Tests for the component files clean-up step.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_cleanup\step\component_files_cleanup
 */
final class component_files_cleanup_test extends advanced_testcase {
    /**
     * Days to keep, for these tests.
     */
    const KEEP_DAYS = 180;

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
     * A file older than the cut-off, belonging to a listed component, is removed.
     *
     * @return void
     */
    public function test_outdated_file_of_a_listed_component_is_removed(): void {
        global $DB;

        $this->resetAfterTest();

        $file = $this->create_file('old.txt', 'assignsubmission_file', $this->outdated());

        $this->run_cleanup(['assignsubmission_file']);

        $this->assertFalse($DB->record_exists('files', ['id' => $file->get_id()]));
    }

    /**
     * A file inside its lifetime is kept, even in a listed component.
     *
     * @return void
     */
    public function test_recent_file_is_kept(): void {
        global $DB;

        $this->resetAfterTest();

        $file = $this->create_file('recent.txt', 'assignsubmission_file', time());

        $this->run_cleanup(['assignsubmission_file']);

        $this->assertTrue($DB->record_exists('files', ['id' => $file->get_id()]));
    }

    /**
     * A file of a component that was not listed is never touched, however old it is.
     *
     * @return void
     */
    public function test_file_of_an_unlisted_component_is_kept(): void {
        global $DB;

        $this->resetAfterTest();

        $file = $this->create_file('other.txt', 'local_cleanup', $this->outdated());

        $this->run_cleanup(['assignsubmission_file']);

        $this->assertTrue(
            $DB->record_exists('files', ['id' => $file->get_id()]),
            'Only the components passed to the step are in scope.'
        );
    }

    /**
     * Both outcomes in one run: the listed component loses its old file, the other keeps its.
     *
     * @return void
     */
    public function test_scope_is_bounded_to_the_listed_components(): void {
        global $DB;

        $this->resetAfterTest();

        $removed = $this->create_file('backup.mbz', 'backup', $this->outdated());
        $kept = $this->create_file('submission.txt', 'assignsubmission_file', $this->outdated());

        $this->run_cleanup(['backup']);

        $this->assertFalse($DB->record_exists('files', ['id' => $removed->get_id()]));
        $this->assertTrue($DB->record_exists('files', ['id' => $kept->get_id()]));
    }

    /**
     * A dry run counts the same files and removes none of them.
     *
     * @return void
     */
    public function test_report_counts_without_deleting(): void {
        global $DB;

        $this->resetAfterTest();

        $file = $this->create_file('old.txt', 'assignsubmission_file', $this->outdated());
        $before = $DB->count_records('files');

        $result = $this->run_report(['assignsubmission_file']);

        $this->assertSame(1, $result->get_records());
        $this->assertTrue($DB->record_exists('files', ['id' => $file->get_id()]));
        $this->assertSame($before, $DB->count_records('files'), 'A dry run must write nothing.');
    }

    /**
     * Run the step under test.
     *
     * @param array $components Components to clean up
     * @return spy_output The captured output
     */
    private function run_cleanup(array $components): spy_output {
        global $DB;

        $output = new spy_output();
        $step = new component_files_cleanup($DB, $components, self::KEEP_DAYS);
        $step->execute($output);

        return $output;
    }

    /**
     * Report on the step under test.
     *
     * @param array $components Components to clean up
     * @return step_result What would be removed
     */
    private function run_report(array $components): step_result {
        global $DB;

        $step = new component_files_cleanup($DB, $components, self::KEEP_DAYS);

        return $step->report(new spy_output());
    }

    /**
     * Get a timestamp older than the configured lifetime.
     *
     * @return int Unix timestamp
     */
    private function outdated(): int {
        return time() - (self::KEEP_DAYS + 1) * DAYSECS;
    }

    /**
     * Create a file record for testing.
     *
     * @param string $filename Name of the file
     * @param string $component Owning component
     * @param int $timecreated Creation time
     * @return stored_file The created file
     */
    private function create_file(string $filename, string $component, int $timecreated): stored_file {
        $filerecord = [
            'contextid' => context_system::instance()->id,
            'component' => $component,
            'filearea' => 'test',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
            'timecreated' => $timecreated,
            'timemodified' => $timecreated,
        ];

        return get_file_storage()->create_file_from_string($filerecord, 'content ' . random_string(16));
    }
}
