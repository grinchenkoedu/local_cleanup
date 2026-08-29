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
use stored_file;

/**
 * Tests for the files checkout clean-up step.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_cleanup\step\files_checkout
 */
final class files_checkout_test extends advanced_testcase {
    /**
     * Number of days after which a backup is considered outdated, for these tests.
     */
    const TIMEOUT_DAYS = 30;

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
     * An outdated backup whose content is shared with another record must be left alone.
     *
     * Moodle deduplicates file content by hash. Unlinking the pool file for one record breaks
     * every other record pointing at the same bytes, so a shared backup is not touched.
     *
     * @return void
     */
    public function test_outdated_backup_with_shared_content_is_kept(): void {
        global $DB;

        $this->resetAfterTest();

        $shared = 'identical backup payload';
        $first = $this->create_backup('course_one.mbz', $shared, $this->outdated());
        $second = $this->create_backup('course_two.mbz', $shared, $this->outdated(), 2);

        $this->assertSame(
            $first->get_contenthash(),
            $second->get_contenthash(),
            'The fixtures must share a content hash for this test to mean anything.'
        );

        $this->run_checkout();

        $this->assertTrue(
            $DB->record_exists('files', ['id' => $first->get_id()]),
            'A backup sharing its content with another record must not be removed.'
        );
        $this->assertTrue(
            $DB->record_exists('files', ['id' => $second->get_id()]),
            'The record sharing the content must survive too.'
        );
        $handle = get_file_storage()->get_file_system()->get_content_file_handle($first);
        $this->assertNotFalse($handle, 'The pool file must still be readable.');
        fclose($handle);
    }

    /**
     * An outdated backup that is the only reference to its content is removed.
     *
     * @return void
     */
    public function test_outdated_backup_with_unique_content_is_removed(): void {
        global $DB;

        $this->resetAfterTest();

        $file = $this->create_backup('lonely.mbz', 'unique ' . random_string(32), $this->outdated());

        $this->run_checkout();

        $this->assertFalse(
            $DB->record_exists('files', ['id' => $file->get_id()]),
            'An outdated backup nothing else references should be removed.'
        );
    }

    /**
     * A backup that is still within its lifetime is kept.
     *
     * @return void
     */
    public function test_recent_backup_is_kept(): void {
        global $DB;

        $this->resetAfterTest();

        $file = $this->create_backup('fresh.mbz', 'recent ' . random_string(32), time());

        $this->run_checkout();

        $this->assertTrue(
            $DB->record_exists('files', ['id' => $file->get_id()]),
            'A backup inside its lifetime must not be removed.'
        );
    }

    /**
     * A file that is not a backup and not a draft is never touched by this step.
     *
     * @return void
     */
    public function test_ordinary_file_is_kept(): void {
        global $DB;

        $this->resetAfterTest();

        $file = $this->create_backup('notes.txt', 'ordinary ' . random_string(32), $this->outdated());

        $this->run_checkout();

        $this->assertTrue(
            $DB->record_exists('files', ['id' => $file->get_id()]),
            'Only backups and drafts are in scope for this step.'
        );
    }

    /**
     * Run the step under test.
     *
     * @return spy_output The captured output
     */
    private function run_checkout(): spy_output {
        global $DB;

        $output = new spy_output();
        $step = new files_checkout($DB, get_file_storage(), self::TIMEOUT_DAYS, self::TIMEOUT_DAYS);
        $step->cleanup($output);

        return $output;
    }

    /**
     * Get a timestamp comfortably older than the configured backup lifetime.
     *
     * @return int Unix timestamp
     */
    private function outdated(): int {
        return time() - (self::TIMEOUT_DAYS + 1) * DAYSECS;
    }

    /**
     * Create a file record for testing.
     *
     * @param string $filename Name of the file
     * @param string $content Content, which determines the content hash
     * @param int $timecreated Creation time
     * @param int $itemid Item id, to allow several records in the same area
     * @return stored_file The created file
     */
    private function create_backup(string $filename, string $content, int $timecreated, int $itemid = 1): stored_file {
        $filerecord = [
            'contextid' => context_system::instance()->id,
            'component' => 'local_cleanup',
            'filearea' => 'test',
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
            'timecreated' => $timecreated,
            'timemodified' => $timecreated,
        ];

        return get_file_storage()->create_file_from_string($filerecord, $content);
    }
}
