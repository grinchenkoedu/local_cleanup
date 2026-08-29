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

namespace local_cleanup\task;

use advanced_testcase;
use context_system;

/**
 * Tests for the scan task.
 *
 * The grace period that protects unlinked files is only as good as the bookkeeping this task
 * writes, so what matters here is that a first sighting survives a second scan.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_cleanup\task\scan
 */
final class scan_test extends advanced_testcase {
    /**
     * A file the {files} table does not reference is recorded, with both timestamps set.
     *
     * @return void
     */
    public function test_an_unreferenced_file_is_recorded(): void {
        global $DB;

        $this->resetAfterTest();

        $hash = $this->write_pool_file('orphan ' . random_string(32));

        $this->run_scan();

        $record = $DB->get_record('local_cleanup_files', ['path' => $this->relative_path($hash)]);

        $this->assertNotFalse($record, 'An unreferenced pool file should be recorded.');
        $this->assertGreaterThan(0, (int)$record->timeconfirmed);
        $this->assertSame(
            (int)$record->timeconfirmed,
            (int)$record->timescanned,
            'On a first sighting both timestamps are the same moment.'
        );
    }

    /**
     * A file the {files} table does reference is left out of the scan results entirely.
     *
     * @return void
     */
    public function test_a_referenced_file_is_not_recorded(): void {
        global $DB;

        $this->resetAfterTest();

        $file = get_file_storage()->create_file_from_string([
            'contextid' => context_system::instance()->id,
            'component' => 'local_cleanup',
            'filearea' => 'test',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'referenced.txt',
        ], 'referenced content ' . random_string(32));

        $this->run_scan();

        $this->assertFalse(
            $DB->record_exists(
                'local_cleanup_files',
                ['path' => $this->relative_path($file->get_contenthash())]
            ),
            'A file {files} still points at is not unlinked.'
        );
    }

    /**
     * A second scan moves the latest sighting and leaves the first one alone.
     *
     * This is what the grace period measures. If the first sighting moved, no file would ever
     * become old enough to remove; if the latest did not, every file would look eligible.
     *
     * @return void
     */
    public function test_a_second_scan_keeps_the_first_sighting(): void {
        global $DB;

        $this->resetAfterTest();

        $hash = $this->write_pool_file('seen twice ' . random_string(32));
        $path = $this->relative_path($hash);

        $this->run_scan();
        $first = $DB->get_record('local_cleanup_files', ['path' => $path], '*', MUST_EXIST);

        // Backdate the first sighting so the second scan has something to preserve.
        $DB->set_field('local_cleanup_files', 'timeconfirmed', (int)$first->timeconfirmed - 30 * DAYSECS, ['id' => $first->id]);
        $DB->set_field('local_cleanup_files', 'timescanned', (int)$first->timescanned - 30 * DAYSECS, ['id' => $first->id]);
        $backdated = $DB->get_record('local_cleanup_files', ['id' => $first->id], '*', MUST_EXIST);

        $this->run_scan();
        $second = $DB->get_record('local_cleanup_files', ['id' => $first->id], '*', MUST_EXIST);

        $this->assertSame(
            (int)$backdated->timeconfirmed,
            (int)$second->timeconfirmed,
            'The first sighting must not move, or nothing ever ages past the grace period.'
        );
        $this->assertGreaterThan(
            (int)$backdated->timescanned,
            (int)$second->timescanned,
            'The latest sighting must move, or nothing is ever corroborated.'
        );
        $this->assertSame(
            1,
            $DB->count_records('local_cleanup_files', ['path' => $path]),
            'A second sighting updates the row rather than adding another.'
        );
    }

    /**
     * A site whose files live elsewhere has no pool to walk, and the task says so.
     *
     * @return void
     */
    public function test_an_alternative_file_system_is_skipped(): void {
        global $CFG, $DB;

        $this->resetAfterTest();

        $this->write_pool_file('would be recorded ' . random_string(32));
        $CFG->alternative_file_system_class = '\\local_cleanup\\nonexistent_file_system';

        $output = $this->run_scan();

        $this->assertSame(
            0,
            $DB->count_records('local_cleanup_files'),
            'There is no local pool to walk, so nothing may be recorded from one.'
        );
        $this->assertStringContainsString('no local file pool', $output);
    }

    /**
     * Run the task, capturing what it writes through mtrace.
     *
     * @return string Everything the task printed
     */
    private function run_scan(): string {
        ob_start();

        try {
            (new scan())->execute();
        } finally {
            $output = ob_get_clean();
        }

        return $output;
    }

    /**
     * Write a file directly into the pool, bypassing the File API.
     *
     * @param string $content Content, whose hash becomes the file name
     * @return string The content hash
     */
    private function write_pool_file(string $content): string {
        global $CFG;

        $hash = sha1($content);
        $path = $CFG->dataroot . DIRECTORY_SEPARATOR . $this->relative_path($hash);

        check_dir_exists(dirname($path));
        file_put_contents($path, $content);

        return $hash;
    }

    /**
     * Build the pool path for a content hash, relative to dataroot.
     *
     * @param string $hash Content hash
     * @return string Relative path
     */
    private function relative_path(string $hash): string {
        return 'filedir' . DIRECTORY_SEPARATOR
            . substr($hash, 0, 2) . DIRECTORY_SEPARATOR
            . substr($hash, 2, 2) . DIRECTORY_SEPARATOR
            . $hash;
    }
}
