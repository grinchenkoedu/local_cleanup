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

/**
 * Tests for the unlinked ("ghost") files clean-up step.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_cleanup\step\ghost_files_cleanup
 */
final class ghost_files_cleanup_test extends advanced_testcase {
    /**
     * Days between the two agreeing scans, for these tests.
     */
    const GRACE_DAYS = 7;

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
     * A recorded file whose content is referenced again must not be deleted.
     *
     * The scan and the clean-up run on separate schedules, so a file uploaded between them can
     * deduplicate onto a hash that was recorded as unlinked. The step must notice and skip it.
     *
     * @return void
     */
    public function test_file_referenced_again_since_the_scan_is_kept(): void {
        global $DB;

        $this->resetAfterTest();

        // A real file, so {files} references this content hash.
        $filerecord = [
            'contextid' => context_system::instance()->id,
            'component' => 'local_cleanup',
            'filearea' => 'test',
            'itemid' => 1,
            'filepath' => '/',
            'filename' => 'reuploaded.txt',
        ];
        $content = 'content that came back ' . random_string(32);
        $file = get_file_storage()->create_file_from_string($filerecord, $content);
        $hash = $file->get_contenthash();

        // An earlier scan had recorded it as unlinked.
        $recordid = $this->record_as_ghost($hash);

        $output = $this->run_cleanup();

        $this->assertFileExists(
            $this->pool_path($hash),
            'A file referenced by {files} must never be unlinked.'
        );
        $this->assertFalse(
            $DB->record_exists('local_cleanup_files', ['id' => $recordid]),
            'The stale scan record should be dropped.'
        );
        $this->assertTrue(
            $output->contains('referenced again'),
            'The step should report that it kept the file.'
        );
        $this->assertTrue(
            $DB->record_exists('files', ['id' => $file->get_id()]),
            'The file record itself is not this step\'s business.'
        );
    }

    /**
     * A genuinely unreferenced file is deleted from disk and from the scan table.
     *
     * @return void
     */
    public function test_unreferenced_file_is_deleted(): void {
        global $DB;

        $this->resetAfterTest();

        $content = 'orphaned content ' . random_string(32);
        $hash = sha1($content);
        $path = $this->write_pool_file($hash, $content);
        $recordid = $this->record_as_ghost($hash);

        $this->assertFalse(
            $DB->record_exists('files', ['contenthash' => $hash]),
            'The fixture must be unreferenced for this test to mean anything.'
        );

        $this->run_cleanup();

        $this->assertFileDoesNotExist($path, 'An unreferenced file should leave the pool.');
        $this->assertFileExists(
            $this->trash_path($hash),
            'It should be in the trash, where Moodle\'s trash clean-up gives a recovery window.'
        );
        $this->assertFalse(
            $DB->record_exists('local_cleanup_files', ['id' => $recordid]),
            'The scan record should be dropped once the file is gone.'
        );
    }

    /**
     * A recorded file that has already vanished from disk drops its row without failing.
     *
     * @return void
     */
    public function test_missing_file_drops_its_record(): void {
        global $DB;

        $this->resetAfterTest();

        $recordid = $this->record_as_ghost(sha1('never written ' . random_string(32)));

        $this->run_cleanup();

        $this->assertFalse(
            $DB->record_exists('local_cleanup_files', ['id' => $recordid]),
            'A record pointing at a file that is already gone should still be cleared.'
        );
    }

    /**
     * An empty scan table is handled without error.
     *
     * @return void
     */
    public function test_nothing_to_do(): void {
        global $DB;

        $this->resetAfterTest();

        $output = $this->run_cleanup();

        $this->assertSame(0, $DB->count_records('local_cleanup_files'));
        $this->assertTrue(
            $output->contains('nothing found'),
            'An empty scan table should be reported as nothing found.'
        );
    }

    /**
     * A dry run counts the unreferenced files and deletes neither row nor file.
     *
     * @return void
     */
    public function test_report_counts_without_deleting(): void {
        global $DB;

        $this->resetAfterTest();

        $content = 'orphaned content ' . random_string(32);
        $hash = sha1($content);
        $path = $this->write_pool_file($hash, $content);
        $recordid = $this->record_as_ghost($hash);

        $result = $this->run_report();

        $this->assertSame(1, $result->get_records());
        $this->assertFileExists($path, 'A dry run must not touch the file.');
        $this->assertTrue(
            $DB->record_exists('local_cleanup_files', ['id' => $recordid]),
            'A dry run must not clear the scan record either.'
        );
    }

    /**
     * A file seen unlinked only once is left alone.
     *
     * Content uploaded between two scans can deduplicate onto a hash an earlier scan recorded
     * as unlinked. One sighting is not evidence, so the grace period must elapse first.
     *
     * @return void
     */
    public function test_a_file_seen_only_once_is_kept(): void {
        global $DB;

        $this->resetAfterTest();

        $content = 'freshly noticed ' . random_string(32);
        $hash = sha1($content);
        $path = $this->write_pool_file($hash, $content);

        // First and latest sighting are the same moment: one scan, no corroboration.
        $recordid = $this->record_as_ghost($hash, 0);

        $this->run_cleanup();

        $this->assertFileExists($path, 'A single sighting must not be enough to remove a file.');
        $this->assertTrue(
            $DB->record_exists('local_cleanup_files', ['id' => $recordid]),
            'The record stays so a later scan can corroborate it.'
        );
    }

    /**
     * A file whose two sightings are closer together than the grace period is left alone.
     *
     * @return void
     */
    public function test_a_file_inside_the_grace_period_is_kept(): void {
        global $DB;

        $this->resetAfterTest();

        $content = 'seen twice, too soon ' . random_string(32);
        $hash = sha1($content);
        $path = $this->write_pool_file($hash, $content);
        $recordid = $this->record_as_ghost($hash, self::GRACE_DAYS - 1);

        $this->run_cleanup();

        $this->assertFileExists($path);
        $this->assertTrue($DB->record_exists('local_cleanup_files', ['id' => $recordid]));
    }

    /**
     * The per-run ceiling stops the step partway and leaves the rest for next time.
     *
     * @return void
     */
    public function test_the_per_run_ceiling_is_honoured(): void {
        global $DB;

        $this->resetAfterTest();

        set_config('maxrecordsperrun', 1, 'local_cleanup');

        foreach (['first', 'second'] as $which) {
            $content = $which . ' orphan ' . random_string(32);
            $hash = sha1($content);
            $this->write_pool_file($hash, $content);
            $this->record_as_ghost($hash);
        }

        $this->run_cleanup();

        $this->assertSame(
            1,
            $DB->count_records('local_cleanup_files'),
            'Exactly one scan record should survive to the next run.'
        );
    }

    /**
     * Run the step under test.
     *
     * @return spy_output The captured output
     */
    private function run_cleanup(): spy_output {
        global $CFG, $DB;

        $output = new spy_output();
        $step = new ghost_files_cleanup($DB, $CFG->dataroot, self::GRACE_DAYS);
        $step->execute($output);

        return $output;
    }

    /**
     * Report on the step under test.
     *
     * @return step_result What would be removed
     */
    private function run_report(): step_result {
        global $CFG, $DB;

        return (new ghost_files_cleanup($DB, $CFG->dataroot, self::GRACE_DAYS))->report(new spy_output());
    }

    /**
     * Record a content hash in the scan table, as the scan task would.
     *
     * @param string $hash Content hash
     * @param int|null $confirmeddaysago Days ago the first sighting was, or null for eligible
     * @return int Id of the inserted record
     */
    private function record_as_ghost(string $hash, ?int $confirmeddaysago = null): int {
        global $DB;

        // Default to a first sighting well outside the grace period, so the file is eligible
        // and each test can say so explicitly when it wants otherwise.
        $confirmeddaysago = $confirmeddaysago ?? self::GRACE_DAYS + 1;
        $now = time();

        return $DB->insert_record('local_cleanup_files', (object)[
            'path' => $this->pool_relative_path($hash),
            'mime' => 'application/octet-stream',
            'size' => 1024,
            'timeconfirmed' => $now - $confirmeddaysago * DAYSECS,
            'timescanned' => $now,
        ]);
    }

    /**
     * Write a file directly into the pool, bypassing the File API.
     *
     * @param string $hash Content hash, which is also the file name
     * @param string $content Content to write
     * @return string Absolute path of the written file
     */
    private function write_pool_file(string $hash, string $content): string {
        $path = $this->pool_path($hash);

        check_dir_exists(dirname($path));
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Build the pool path for a content hash, relative to dataroot.
     *
     * @param string $hash Content hash
     * @return string Relative path
     */
    private function pool_relative_path(string $hash): string {
        return 'filedir' . DIRECTORY_SEPARATOR
            . substr($hash, 0, 2) . DIRECTORY_SEPARATOR
            . substr($hash, 2, 2) . DIRECTORY_SEPARATOR
            . $hash;
    }

    /**
     * Build the absolute trash path for a content hash.
     *
     * @param string $hash Content hash
     * @return string Absolute path
     */
    private function trash_path(string $hash): string {
        global $CFG;

        return $CFG->dataroot . DIRECTORY_SEPARATOR . 'trashdir'
            . DIRECTORY_SEPARATOR . substr($hash, 0, 2)
            . DIRECTORY_SEPARATOR . substr($hash, 2, 2)
            . DIRECTORY_SEPARATOR . $hash;
    }

    /**
     * Build the absolute pool path for a content hash.
     *
     * @param string $hash Content hash
     * @return string Absolute path
     */
    private function pool_path(string $hash): string {
        global $CFG;

        return $CFG->dataroot . DIRECTORY_SEPARATOR . $this->pool_relative_path($hash);
    }
}
