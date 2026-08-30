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

namespace local_cleanup\table;

use advanced_testcase;
use moodle_url;

/**
 * Tests for the unlinked files report table.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_cleanup\table\ghost_table
 */
final class ghost_table_test extends advanced_testcase {
    /**
     * Days between the two agreeing scans, for these tests.
     */
    const GRACE_DAYS = 7;

    /**
     * The path and the detected type are escaped before they reach the page.
     *
     * Both are values somebody else influenced: the path ends in a content hash, and the type
     * comes from mime_content_type() reading an uploaded file.
     *
     * @return void
     */
    public function test_values_are_escaped(): void {
        $this->resetAfterTest();

        $table = $this->create_table();
        $row = (object)[
            'path' => 'filedir/ab/cd/<script>alert(1)</script>',
            'mime' => '<b>text/plain</b>',
        ];

        $this->assertStringNotContainsString('<script>', $table->col_path($row));
        $this->assertStringContainsString('&lt;script&gt;', $table->col_path($row));
        $this->assertStringNotContainsString('<b>', $table->col_mime($row));
    }

    /**
     * A file still inside its grace period is marked as waiting.
     *
     * Without this an administrator cannot tell why a listed file has not been removed.
     *
     * @return void
     */
    public function test_a_file_inside_the_grace_period_is_marked(): void {
        $this->resetAfterTest();

        $table = $this->create_table();
        $now = time();

        $waiting = (object)[
            'timeconfirmed' => $now - 1 * DAYSECS,
            'timescanned' => $now,
        ];
        $eligible = (object)[
            'timeconfirmed' => $now - 30 * DAYSECS,
            'timescanned' => $now,
        ];

        $this->assertStringContainsString('awaiting', $table->col_timeconfirmed($waiting));
        $this->assertStringNotContainsString('awaiting', $table->col_timeconfirmed($eligible));
    }

    /**
     * A row carried over from before the bookkeeping says so rather than showing 1970.
     *
     * @return void
     */
    public function test_a_row_with_no_first_sighting(): void {
        $this->resetAfterTest();

        $cell = $this->create_table()->col_timeconfirmed((object)[
            'timeconfirmed' => 0,
            'timescanned' => 0,
        ]);

        $this->assertSame(get_string('awaitingscan', 'local_cleanup'), $cell);
    }

    /**
     * Build the table under test.
     *
     * @return ghost_table The table
     */
    private function create_table(): ghost_table {
        return new ghost_table(
            'test',
            new moodle_url('/local/cleanup/ghost.php'),
            self::GRACE_DAYS
        );
    }
}
