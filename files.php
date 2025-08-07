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
 * Files management page for cleanup plugin.
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
require_once($CFG->libdir . '/formslib.php');

use local_cleanup\finder;
use local_cleanup\form\filter_form;

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/cleanup/files.php');
$PAGE->set_title(get_string('files'));
$PAGE->set_heading(get_string('files'));
$PAGE->set_pagelayout('admin');

require_login();

if (!is_siteadmin()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Forbidden!');
}

$page = optional_param('page', 0, PARAM_INT);
$limit = $CFG->cleanup_items_per_page ?? finder::LIMIT_DEFAULT;

$filter = [
    'filesize' => optional_param('filesize', 50, PARAM_INT),
    'name_like' => optional_param('name_like', '', PARAM_TEXT),
    'user_like' => optional_param('user_like', '', PARAM_TEXT),
    'component' => optional_param('component', '', PARAM_TEXT),
    'user_deleted' => optional_param('user_deleted', '', PARAM_TEXT),
];

$filterform = new filter_form(null, $filter);

if ($filterform->is_cancelled()) {
    redirect($PAGE->url);
}

$redirecturl = new moodle_url($PAGE->url, array_merge($filter, ['page' => $page]));

$finder = new finder($DB);
$items = $finder->find($limit, $page * $limit, $filter);
$totalitems = $finder->count($filter);
$maxitems = pow(10, 3) * ($page + 1);

$table = new html_table();
$table->head = [
    get_string('filename', 'backup'),
    get_string('component', 'cache'),
    get_string('size'),
    get_string('user', 'admin'),
    get_string('date'),
    '',
];

$table->size = ['30%', '15%', '10%', '30%', '15%', '1%'];

while ($items->valid()) {
    $item = $items->current();

    $actions = [
        html_writer::link(
            new moodle_url('/local/cleanup/download.php', ['id' => $item->id]),
            $OUTPUT->pix_icon('i/down', get_string('download'))
        ),
    ];

    if (
        preg_match('/^mod_/', $item->component)
        || ($item->component === 'backup' && $item->filearea === 'course')
    ) {
        array_unshift(
            $actions,
            html_writer::link(
                new moodle_url('/local/cleanup/open.php', ['id' => $item->id]),
                $OUTPUT->pix_icon('i/preview', get_string('view')),
                [
                    'target' => '_blank',
                ]
            )
        );
    }

    $actions[] = html_writer::link(
        new moodle_url('/local/cleanup/remove.php', ['id' => $item->id, 'redirect' => $redirecturl]),
        $OUTPUT->pix_icon('t/delete', get_string('delete'))
    );

    if (!$item->user_deleted) {
        $user = html_writer::link(
            new moodle_url('/user/profile.php', ['id' => $item->userid]),
            fullname($item),
            [
                'target' => '_blank',
            ]
        );
    } else {
        $user = html_writer::tag('del', fullname($item));
    }

    $table->data[] = [
        $item->filename,
        sprintf('%s, %s', $item->component, $item->filearea),
        sprintf(
            '%.1f %s',
            $item->filesize / pow(1024, 2),
            get_string('sizemb')
        ),
        $user,
        date('Y-m-d H:i', $item->timecreated),
        implode(' ', $actions),
    ];

    $items->next();
}

$pagination = $OUTPUT->paging_bar(
    $totalitems > $maxitems ? $maxitems : $totalitems,
    $page,
    $limit,
    new moodle_url($PAGE->url, $filter)
);

echo $OUTPUT->header();

$filterform->display();

if (count($table->data) !== 0) {
    echo html_writer::tag(
        'p',
        get_string('files_total', 'local_cleanup') . ': ' . $totalitems
    );
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nothingtoshow', 'local_cleanup'));
}

echo $OUTPUT->box($pagination, 'text-center');
echo $OUTPUT->footer();
