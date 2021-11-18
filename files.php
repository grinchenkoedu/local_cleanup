<?php
/**
 * @global moodle_page $PAGE
 * @global moodle_database $DB
 * @global stdClass $USER
 * @global stdClass $CFG
 * @global renderer_base $OUTPUT
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');

use local_cleanup\finder;
use local_cleanup\form\filter_form;

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/cleanup/files.php');
$PAGE->set_title('Users files');
$PAGE->set_heading('Users files');
$PAGE->set_pagelayout('admin');

require_login();

$page = optional_param('page', 0, PARAM_INT);
$limit = $CFG->cleanup_items_per_page ?? finder::LIMIT_DEFAULT;

$filter = [
    'name_like' => optional_param('name_like', '', PARAM_TEXT),
    'user_like' => optional_param('user_like', '', PARAM_TEXT),
    'component' => optional_param('component', '', PARAM_TEXT),
];

$filter_form = new filter_form(null, $filter);

if ($filter_form->is_cancelled()) {
    redirect($PAGE->url);
}

$is_admin = is_siteadmin();
$redirect_url = new moodle_url($PAGE->url, array_merge($filter, ['page' => $page]));

$finder = new finder($DB, $USER->id, $is_admin);
$items = $finder->find($limit, $page * $limit, $filter);
$totalItems = $finder->count($filter);
$maxItems = pow(10, 3) * ($page + 1);

$table = new html_table();
$table->head = [
    get_string('filename', 'backup'),
    get_string('component', 'cache'),
    get_string('size'),
    get_string('user', 'admin'),
    get_string('date'),
    ''
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
                    'target' => '_blank'
                ]
            )
        );
    }

    if ($item->userid === $USER->id || $is_admin) {
        $actions[] = html_writer::link(
            new moodle_url('/local/cleanup/remove.php', ['id' => $item->id, 'redirect' => $redirect_url]),
            $OUTPUT->pix_icon('t/delete', get_string('delete'))
        );
    }

    $table->data[] = [
        $item->filename,
        sprintf('%s, %s', $item->component, $item->filearea),
        sprintf(
            '%.1f %s',
            $item->filesize / pow(1024, 2),
            get_string('sizemb')
        ),
        html_writer::link(
            new moodle_url('/user/profile.php', ['id' => $item->userid]),
            fullname($item),
            [
                'target' => '_blank'
            ]
        ),
        date('Y-m-d H:i', $item->timecreated),
        implode(' ', $actions)
    ];

    $items->next();
}

$pagination = $OUTPUT->paging_bar(
    $totalItems > $maxItems ? $maxItems : $totalItems,
    $page,
    $limit,
    new moodle_url($PAGE->url, $filter)
);

echo $OUTPUT->header();

$filter_form->display();

if (count($table->data) !== 0) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nothingtoshow', 'local_cleanup'));
}

echo $OUTPUT->box($pagination, 'text-center');
echo $OUTPUT->footer();
