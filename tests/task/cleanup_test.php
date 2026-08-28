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
use stored_file;

/**
 * Tests for the clean-up scheduled task.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_cleanup\task\cleanup
 */
final class cleanup_test extends advanced_testcase {

    /**
     * Number of days after which a backup is considered outdated, for these tests.
     */
    const TIMEOUT_DAYS = 30;

    /**
     * With auto-remove disabled the task must not delete anything.
     *
     * Every destructive step was gated on this setting except the file checkout, which ran
     * unconditionally, so sites that had deliberately left it off still lost their backups.
     *
     * @return void
     */
    public function test_nothing_is_removed_when_autoremove_is_disabled(): void {
        global $DB;

        $this->resetAfterTest();

        set_config('cleanup_run_autoremove', 0);
        set_config('cleanup_backup_timeout_days', self::TIMEOUT_DAYS);

        $file = $this->create_outdated_backup();
        $before = $DB->count_records('files');

        $this->execute_task();

        $this->assertTrue(
            $DB->record_exists('files', ['id' => $file->get_id()]),
            'An outdated backup must survive while auto-remove is disabled.'
        );
        $this->assertSame(
            $before,
            $DB->count_records('files'),
            'No file record at all should be removed while auto-remove is disabled.'
        );
    }

    /**
     * With auto-remove enabled the outdated backup is removed.
     *
     * This is the control for the test above: without it, that test would still pass if the
     * task had stopped doing anything at all.
     *
     * @return void
     */
    public function test_outdated_backup_is_removed_when_autoremove_is_enabled(): void {
        global $DB;

        $this->resetAfterTest();

        set_config('cleanup_run_autoremove', 1);
        set_config('cleanup_backup_timeout_days', self::TIMEOUT_DAYS);

        $file = $this->create_outdated_backup();

        $this->execute_task();

        $this->assertFalse(
            $DB->record_exists('files', ['id' => $file->get_id()]),
            'An outdated backup should be removed once auto-remove is enabled.'
        );
    }

    /**
     * Run the task, discarding the progress it writes through mtrace.
     *
     * @return void
     */
    private function execute_task(): void {
        $task = new cleanup();

        ob_start();

        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Create a backup file old enough to be outdated, referenced by nothing else.
     *
     * @return stored_file The created file
     */
    private function create_outdated_backup(): stored_file {
        $timecreated = time() - (self::TIMEOUT_DAYS + 1) * DAYSECS;

        $filerecord = [
            'contextid' => context_system::instance()->id,
            'component' => 'local_cleanup',
            'filearea' => 'test',
            'itemid' => 1,
            'filepath' => '/',
            'filename' => 'outdated.mbz',
            'timecreated' => $timecreated,
            'timemodified' => $timecreated,
        ];

        return get_file_storage()->create_file_from_string(
            $filerecord,
            'outdated backup ' . random_string(32)
        );
    }
}
