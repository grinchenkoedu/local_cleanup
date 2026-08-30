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

use table_sql;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->libdir . '/tablelib.php');

/**
 * Shared behaviour for the plugin's reports.
 *
 * table_sql does not escape cell contents - that is what col_ methods are for - and the two
 * outputs a report produces want opposite things. Both reports show values somebody else chose,
 * so both need this and neither should carry its own copy.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class report_table extends table_sql {
    /**
     * Neutralise a value a spreadsheet would treat as a formula.
     *
     * A cell beginning =, +, - or @ is executed when the file is opened, so a file named
     * "=cmd|'/c calc'!A0.pdf" runs on the machine of whoever opens the export. Prefixing an
     * apostrophe is the standard defence: spreadsheets read it as "this is text" and drop it.
     *
     * @param string $value The raw value
     * @return string Safe to place in a spreadsheet cell
     */
    public static function neutralise_formula(string $value): string {
        if ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Render text safely for whichever output this is.
     *
     * HTML wants entities; a spreadsheet wants none of them but does need formulas defused.
     *
     * @param string $value The raw value
     * @return string Cell contents
     */
    protected function text(string $value): string {
        if ($this->is_downloading()) {
            return self::neutralise_formula($value);
        }

        return s($value);
    }

    /**
     * Say so plainly when there is nothing to list.
     *
     * @return void
     */
    public function print_nothing_to_display() {
        global $OUTPUT;

        echo $this->render_reset_button();

        $this->print_initials_bar();

        echo $OUTPUT->notification(get_string('nothingtoshow', 'local_cleanup'), 'notifymessage');
    }
}
