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

namespace local_cleanup;

use advanced_testcase;
use context_system;
use stored_file;

/**
 * Tests for the file removal contract that remove.php depends on.
 *
 * remove.php used to delete every {files} record sharing a content hash, which destroyed
 * unrelated records because Moodle deduplicates file content. It now calls
 * stored_file::delete(). The page itself cannot be exercised from PHPUnit, so these tests pin
 * the behaviour that makes the new call correct: one record removed, shared content untouched.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class file_removal_test extends advanced_testcase {
    /**
     * Deleting one record must leave a record that shares its content alone.
     *
     * @return void
     */
    public function test_deleting_one_record_keeps_the_record_sharing_its_content(): void {
        global $DB;

        $this->resetAfterTest();

        $shared = 'shared payload ' . random_string(32);
        $first = $this->create_file('first.mbz', $shared, 1);
        $second = $this->create_file('second.mbz', $shared, 2);

        $this->assertSame(
            $first->get_contenthash(),
            $second->get_contenthash(),
            'The fixtures must share a content hash for this test to mean anything.'
        );

        $first->delete();

        $this->assertFalse(
            $DB->record_exists('files', ['id' => $first->get_id()]),
            'The record that was deleted should be gone.'
        );
        $this->assertTrue(
            $DB->record_exists('files', ['id' => $second->get_id()]),
            'The record sharing the content must survive.'
        );
        $this->assertFileExists(
            $this->pool_path($second->get_contenthash()),
            'The content must stay in the pool while another record references it.'
        );

        $handle = get_file_storage()->get_file_system()->get_content_file_handle($second);
        $this->assertNotFalse($handle, 'The surviving record must still be readable.');
        fclose($handle);
    }

    /**
     * Deleting the only record referencing some content removes it from the pool.
     *
     * @return void
     */
    public function test_deleting_the_last_reference_clears_the_pool_file(): void {
        global $DB;

        $this->resetAfterTest();

        $file = $this->create_file('only.mbz', 'sole payload ' . random_string(32), 1);
        $poolpath = $this->pool_path($file->get_contenthash());

        $this->assertFileExists($poolpath, 'The fixture should be in the pool to begin with.');

        $file->delete();

        $this->assertFalse(
            $DB->record_exists('files', ['id' => $file->get_id()]),
            'The record should be gone.'
        );
        $this->assertFileDoesNotExist(
            $poolpath,
            'Content nothing references any more should leave the pool.'
        );
    }

    /**
     * Create a file record for testing.
     *
     * @param string $filename Name of the file
     * @param string $content Content, which determines the content hash
     * @param int $itemid Item id, to allow several records in the same area
     * @return stored_file The created file
     */
    private function create_file(string $filename, string $content, int $itemid): stored_file {
        $filerecord = [
            'contextid' => context_system::instance()->id,
            'component' => 'local_cleanup',
            'filearea' => 'test',
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
        ];

        return get_file_storage()->create_file_from_string($filerecord, $content);
    }

    /**
     * Build the absolute pool path for a content hash.
     *
     * @param string $hash Content hash
     * @return string Absolute path
     */
    private function pool_path(string $hash): string {
        global $CFG;

        return $CFG->dataroot . DIRECTORY_SEPARATOR . 'filedir'
            . DIRECTORY_SEPARATOR . substr($hash, 0, 2)
            . DIRECTORY_SEPARATOR . substr($hash, 2, 2)
            . DIRECTORY_SEPARATOR . $hash;
    }
}
