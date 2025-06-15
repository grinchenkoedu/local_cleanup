<?php
/**
 * @global moodle_page $PAGE
 * @global moodle_database $DB
 * @global stdClass $USER
 * @global stdClass $CFG
 * @global renderer_base $OUTPUT
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

$items = $DB->get_recordset('cleanup', [], 'size DESC', '*', $page * $limit, $limit);
$total_items = $DB->count_records('cleanup');
$total_size = $DB->get_field('cleanup', 'SUM(size)', []);

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
        implode(' ', $actions)
    ];

    $items->next();
}

$pagination = $OUTPUT->paging_bar($total_items, $page, $limit, $PAGE->url);

echo $OUTPUT->header();

echo $OUTPUT->box(
    html_writer::tag('p',
        html_writer::tag('b',
            get_string(
                'ghosttotalheader',
                'local_cleanup',
                [
                    'files' => $total_items,
                    'size' => sprintf('%.3f', $total_size / pow(1024, 3)),
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
