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
 * @var stdClass $USER
 * @var stdClass $CFG
 * @var renderer_base $OUTPUT
 */

require_once(__DIR__ . '/../../config.php');

use core\task\manager as task_manager;
use local_cleanup\task\cleanup;

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/cleanup/ghost.php');
$PAGE->set_title(get_string('ghostfiles', 'local_cleanup'));
$PAGE->set_heading(get_string('ghostfiles', 'local_cleanup'));
$PAGE->set_pagelayout('admin');

require_login();

if (!is_siteadmin()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Forbidden!');
}

$task = task_manager::get_scheduled_task(cleanup::class);
$page = optional_param('page', 0, PARAM_INT);
$limit = 250;

$items = $DB->get_recordset('local_cleanup_files', [], 'size DESC', '*', $page * $limit, $limit);
$totalitems = $DB->count_records('local_cleanup_files');
$totalsize = $DB->get_field('local_cleanup_files', 'SUM(size)', []);

$table = new html_table();
$table->head = [
    get_string('file'),
    'MIME',
    get_string('size'),
    '',
];

while ($items->valid()) {
    $item = $items->current();

    $actions = [
        html_writer::link(
            new moodle_url('/local/cleanup/download.php', ['path' => $item->path]),
            $OUTPUT->pix_icon('i/down', get_string('download'))
        ),
    ];

    $table->data[] = [
        $item->path,
        $item->mime,
        sprintf(
            '%.1f %s',
            $item->size / pow(1024, 2),
            get_string('sizemb')
        ),
        implode(' ', $actions),
    ];

    $items->next();
}

$pagination = $OUTPUT->paging_bar($totalitems, $page, $limit, $PAGE->url);

echo $OUTPUT->header();

echo $OUTPUT->box(
    html_writer::tag('p',
        html_writer::tag('b',
            get_string(
                'ghosttotalheader',
                'local_cleanup',
                [
                    'files' => $totalitems,
                    'size' => sprintf('%.3f', $totalsize / pow(1024, 3)),
                    'cleanup_date' => date(DATE_ISO8601, $task->get_next_run_time()),
                ]
            )
        )
    )
);

if (count($table->data) !== 0) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nothingtoshow', 'local_cleanup'), 'notifysuccess');
}

echo $OUTPUT->box($pagination, 'text-center');
echo $OUTPUT->footer();
