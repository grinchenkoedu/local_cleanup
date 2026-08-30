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
use moodle_url;

/**
 * The unlinked files report.
 *
 * Lists what the scan found in the file pool that no {files} record points at. Nothing here is
 * removed until two scans a grace period apart have both seen it, so the report shows when each
 * file was first noticed - otherwise an administrator cannot tell why something is still listed.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ghost_table extends report_table {
    /**
     * Seconds that must separate the two sightings before a file is removed.
     *
     * @var int
     */
    private $grace;

    /**
     * Constructor.
     *
     * @param string $uniqueid Identifier for this table's preferences
     * @param moodle_url $baseurl The report's own URL
     * @param int $gracedays Days that must separate the two agreeing scans
     */
    public function __construct(string $uniqueid, moodle_url $baseurl, int $gracedays) {
        parent::__construct($uniqueid);

        $this->grace = $gracedays * DAYSECS;

        $this->define_baseurl($baseurl);
        $this->define_columns(['path', 'mime', 'size', 'timeconfirmed', 'actions']);
        $this->define_headers([
            get_string('file'),
            get_string('mimetype', 'local_cleanup'),
            get_string('size'),
            get_string('firstseen', 'local_cleanup'),
            '',
        ]);

        $this->no_sorting('actions');
        $this->sortable(true, 'size', SORT_DESC);
        $this->collapsible(false);

        // Everything the scan recorded is listed; table_sql insists on a WHERE clause, so this
        // is a placeholder rather than a filter waiting to be written. timescanned is selected
        // without being a column because col_timeconfirmed() needs it to work out the badge.
        $this->set_sql('id, path, mime, size, timeconfirmed, timescanned', '{local_cleanup_files}', '1 = 1');
        $this->set_count_sql('SELECT COUNT(1) FROM {local_cleanup_files}');
    }

    /**
     * Show where the file sits in the pool.
     *
     * @param object $row The record
     * @return string Cell contents
     */
    public function col_path($row): string {
        return $this->text((string)$row->path);
    }

    /**
     * Show the detected type.
     *
     * This comes from mime_content_type() reading a file somebody else uploaded, so it is no
     * more trustworthy than the file name.
     *
     * @param object $row The record
     * @return string Cell contents
     */
    public function col_mime($row): string {
        return $this->text((string)$row->mime);
    }

    /**
     * Show the size in readable units rather than bytes.
     *
     * @param object $row The record
     * @return string Cell contents
     */
    public function col_size($row): string {
        return display_size((int)$row->size);
    }

    /**
     * Show when the file was first seen unlinked, and whether that is long enough ago.
     *
     * @param object $row The record
     * @return string Cell contents
     */
    public function col_timeconfirmed($row): string {
        if (empty($row->timeconfirmed)) {
            return get_string('awaitingscan', 'local_cleanup');
        }

        $when = userdate((int)$row->timeconfirmed, get_string('strftimedatetimeshort', 'langconfig'));

        if ($this->is_downloading()) {
            return $when;
        }

        if ((int)$row->timescanned - (int)$row->timeconfirmed < $this->grace) {
            return $when . ' ' . html_writer::tag(
                'span',
                get_string('awaitingsecondscan', 'local_cleanup'),
                ['class' => 'badge badge-info']
            );
        }

        return $when;
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

        return html_writer::link(
            new moodle_url('/local/cleanup/download.php', ['path' => $row->path]),
            $OUTPUT->pix_icon('i/down', get_string('download'))
        );
    }
}
