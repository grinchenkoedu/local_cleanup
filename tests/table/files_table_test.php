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
use local_cleanup\finder;
use moodle_url;

/**
 * Tests for the files report table.
 *
 * table_sql does not escape cell contents, so every column that shows a database value has to
 * do it itself. A file name is chosen by whoever uploaded the file.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_cleanup\table\files_table
 */
final class files_table_test extends advanced_testcase {
    /**
     * A file name containing markup is escaped before it reaches the page.
     *
     * @return void
     */
    public function test_the_file_name_is_escaped(): void {
        global $DB;

        $this->resetAfterTest();

        $table = new files_table(
            'test',
            new finder($DB),
            ['filesize' => 0],
            new moodle_url('/local/cleanup/files.php'),
            false
        );

        $cell = $table->col_filename((object)['filename' => '<script>alert(1)</script>.pdf']);

        $this->assertStringNotContainsString('<script>', $cell);
        $this->assertStringContainsString('&lt;script&gt;', $cell);
    }

    /**
     * A file name a spreadsheet would run as a formula is defused.
     *
     * @dataProvider formula_provider
     * @param string $filename The name as uploaded
     * @param string $expected What belongs in the cell
     * @return void
     */
    public function test_formulas_are_neutralised(string $filename, string $expected): void {
        $this->assertSame($expected, report_table::neutralise_formula($filename));
    }

    /**
     * Names a spreadsheet treats as formulas, and one it does not.
     *
     * @return array[] Cases
     */
    public static function formula_provider(): array {
        return [
            'equals starts a formula' => ["=cmd|'/c calc'!A0.pdf", "'=cmd|'/c calc'!A0.pdf"],
            'plus starts a formula' => ['+1+1.pdf', "'+1+1.pdf"],
            'minus starts a formula' => ['-1+1.pdf', "'-1+1.pdf"],
            'at starts a formula' => ['@SUM(A1).pdf', "'@SUM(A1).pdf"],
            'an ordinary name is left alone' => ['essay.pdf', 'essay.pdf'],
            'an empty name is left alone' => ['', ''],
        ];
    }
}
