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

use html_writer;
use local_cleanup\finder;
use moodle_url;
use table_sql;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->libdir . '/tablelib.php');

/**
 * The files report.
 *
 * A table_sql rather than a hand-built html_table: it brings sortable columns, paging whose
 * total matches the rows, CSV and Excel download, and - the reason it matters here - escaping
 * by default. The previous table put database values straight into cells, which html_writer
 * does not escape.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class files_table extends table_sql {
    /**
     * Whether the viewer may delete a file.
     *
     * @var bool
     */
    private $candelete;

    /**
     * Where to send the browser back to after a deletion.
     *
     * @var moodle_url
     */
    private $returnurl;

    /**
     * Constructor.
     *
     * @param string $uniqueid Identifier for this table's preferences
     * @param finder $finder Builds the query behind the report
     * @param array $filter Filter criteria from the form
     * @param moodle_url $baseurl The report's own URL, carrying the current filter
     * @param bool $candelete Whether the viewer holds local/cleanup:deletefiles
     */
    public function __construct(
        string $uniqueid,
        finder $finder,
        array $filter,
        moodle_url $baseurl,
        bool $candelete
    ) {
        parent::__construct($uniqueid);

        $this->candelete = $candelete;
        $this->returnurl = $baseurl;

        $this->define_baseurl($baseurl);
        $this->define_columns(['filename', 'component', 'filesize', 'user', 'timecreated', 'actions']);
        $this->define_headers([
            get_string('filename', 'backup'),
            get_string('component', 'cache'),
            get_string('size'),
            get_string('user', 'admin'),
            get_string('date'),
            '',
        ]);

        // The owner column is assembled from several name fields and the actions column is
        // links, so neither maps onto something the database can order by.
        $this->no_sorting('user');
        $this->no_sorting('actions');
        $this->sortable(true, 'filesize', SORT_DESC);
        $this->collapsible(false);

        $parts = $finder->get_sql_parts($filter);
        $this->set_sql($parts['fields'], $parts['from'], $parts['where'], $parts['params']);
        $this->set_count_sql(
            sprintf('SELECT COUNT(f.id) FROM %s WHERE %s', $parts['from'], $parts['where']),
            $parts['params']
        );
    }

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
     * table_sql does not escape cell contents - that is what these methods are for - and the
     * two outputs need opposite things: HTML wants entities, a spreadsheet wants none of them
     * but does need formulas defused.
     *
     * @param string $value The raw value
     * @return string Cell contents
     */
    private function text(string $value): string {
        if ($this->is_downloading()) {
            return self::neutralise_formula($value);
        }

        return s($value);
    }

    /**
     * Show the file name.
     *
     * @param object $row The record
     * @return string Cell contents
     */
    public function col_filename($row): string {
        return $this->text((string)$row->filename);
    }

    /**
     * Show the component and its file area together.
     *
     * @param object $row The record
     * @return string Cell contents
     */
    public function col_component($row): string {
        return $this->text(sprintf('%s, %s', $row->component, $row->filearea));
    }

    /**
     * Show the size in readable units rather than bytes.
     *
     * @param object $row The record
     * @return string Cell contents
     */
    public function col_filesize($row): string {
        return display_size((int)$row->filesize);
    }

    /**
     * Show the owner, struck through when the account has been deleted.
     *
     * @param object $row The record
     * @return string Cell contents
     */
    public function col_user($row): string {
        if (empty($row->userid)) {
            return '';
        }

        $name = fullname($row);

        if ($this->is_downloading()) {
            return self::neutralise_formula($name);
        }

        if (!empty($row->user_deleted)) {
            return html_writer::tag('del', s($name));
        }

        return html_writer::link(
            new moodle_url('/user/profile.php', ['id' => $row->userid]),
            s($name),
            ['target' => '_blank']
        );
    }

    /**
     * Show when the file was uploaded.
     *
     * @param object $row The record
     * @return string Cell contents
     */
    public function col_timecreated($row): string {
        return userdate((int)$row->timecreated, get_string('strftimedatetimeshort', 'langconfig'));
    }

    /**
     * Show what can be done with the file.
     *
     * @param object $row The record
     * @return string Cell contents
     */
    public function col_actions($row): string {
        global $OUTPUT;

        if ($this->is_downloading()) {
            return '';
        }

        $actions = [];

        if ($this->is_previewable($row)) {
            $actions[] = html_writer::link(
                new moodle_url('/local/cleanup/open.php', ['id' => $row->id]),
                $OUTPUT->pix_icon('i/preview', get_string('view')),
                ['target' => '_blank']
            );
        }

        $actions[] = html_writer::link(
            new moodle_url('/local/cleanup/download.php', ['id' => $row->id]),
            $OUTPUT->pix_icon('i/down', get_string('download'))
        );

        if ($this->candelete) {
            $actions[] = html_writer::link(
                new moodle_url('/local/cleanup/remove.php', [
                    'id' => $row->id,
                    'redirect' => $this->returnurl->out_as_local_url(false),
                ]),
                $OUTPUT->pix_icon('t/delete', get_string('delete'))
            );
        }

        return implode(' ', $actions);
    }

    /**
     * Whether there is somewhere in the site to show this file in context.
     *
     * @param object $row The record
     * @return bool True when open.php can resolve it
     */
    private function is_previewable($row): bool {
        return (bool)preg_match('/^mod_/', $row->component)
            || ($row->component === 'backup' && $row->filearea === 'course');
    }

    /**
     * Say so plainly when a filter matches nothing.
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
