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

/**
 * Ghost files management page for cleanup plugin.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @var moodle_page $PAGE
 * @var moodle_database $DB
 * @var renderer_base $OUTPUT
 */

require_once(__DIR__ . '/../../config.php');

use core\task\manager as task_manager;
use local_cleanup\config;
use local_cleanup\table\ghost_table;
use local_cleanup\task\cleanup;

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/cleanup/ghost.php');
$PAGE->set_title(get_string('ghostfiles', 'local_cleanup'));
$PAGE->set_heading(get_string('ghostfiles', 'local_cleanup'));
$PAGE->set_pagelayout('admin');

require_login();
require_capability('local/cleanup:view', context_system::instance());

$table = new ghost_table('local_cleanup_ghost_report', $PAGE->url, config::ghost_grace_days());
$table->is_downloading(
    optional_param('download', '', PARAM_ALPHA),
    'local_cleanup_unlinked',
    get_string('ghostfiles', 'local_cleanup')
);

if (!$table->is_downloading()) {
    // get_scheduled_task() returns false when no task_scheduled row matches, which happens
    // after a partial upgrade or if somebody deletes the task in Site administration. The
    // totals are still worth showing, so only the date goes missing.
    $task = task_manager::get_scheduled_task(cleanup::class);
    $nextrun = $task
        ? userdate($task->get_next_run_time(), get_string('strftimedatetimeshort', 'langconfig'))
        : get_string('cleanupnotscheduled', 'local_cleanup');

    echo $OUTPUT->header();

    echo $OUTPUT->box(
        html_writer::tag(
            'p',
            html_writer::tag(
                'b',
                get_string(
                    'ghosttotalheader',
                    'local_cleanup',
                    [
                        'files' => $DB->count_records('local_cleanup_files'),
                        'size' => sprintf(
                            '%.3f',
                            ($DB->get_field('local_cleanup_files', 'SUM(size)', []) ?: 0) / pow(1024, 3)
                        ),
                        'cleanup_date' => $nextrun,
                    ]
                )
            )
        )
    );
}

$table->out(config::items_per_page(), true);

if (!$table->is_downloading()) {
    echo $OUTPUT->footer();
}
