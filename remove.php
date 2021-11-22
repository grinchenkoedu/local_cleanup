<?php
/**
 * @global stdClass $CFG
 * @global stdClass $USER
 * @global moodle_page $PAGE
 * @global moodle_database $DB
 * @global renderer_base $OUTPUT
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');

use core\notification;
use local_cleanup\form\confirmation_form;

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/cleanup/remove.php');
$PAGE->set_title('Remove file');
$PAGE->set_heading('Remove file');
$PAGE->set_pagelayout('default');

require_login();

$id = optional_param('id', 0, PARAM_INT);
$file = $DB->get_record('files', ['id' => $id], '*', MUST_EXIST);

if (!is_siteadmin()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Forbidden!');
}

$redirect_url = new moodle_url(optional_param('redirect', '/local/cleanup/files.php', PARAM_TEXT));
$similar = $DB->get_records('files', ['contenthash' => $file->contenthash]);
$similar = array_filter(
    $DB->get_records('files', ['contenthash' => $file->contenthash]),
    function ($item) use ($file) {
        return $item->id !== $file->id;
    }
);

$table = new html_table();
$table->head = [
    get_string('filename', 'backup'),
    get_string('component', 'cache'),
    get_string('user', 'admin'),
    get_string('date'),
    ''
];
$table->size = ['35%', '15%', '25%', '14%', '1%'];

foreach ($similar as $item) {
    if (empty($owner) || $owner->id !== $item->userid) {
        $owner = $DB->get_record('user', ['id' => $item->userid]);
    }

    $table->data[] = [
        $item->filename,
        sprintf('%s, %s', $item->component, $item->filearea),
        fullname($owner),
        date('Y-m-d H:i', $item->timecreated),
        html_writer::link(
            new moodle_url('/local/cleanup/open.php', ['id' => $item->id]),
            $OUTPUT->pix_icon('i/preview', get_string('view')),
            [
                'target' => '_blank'
            ]
        ),
    ];
}

$form_parameters = [
    'id' => $id,
    'message' => get_string(
        'removeconfirm',
        'local_cleanup',
        [
            'name' => $file->filepath . $file->filename,
            'id' => $id,
        ]
    ),
    'redirect' => (string)$redirect_url,
];

if (count($table->data) > 0) {
    $form_parameters['html'] =
        html_writer::tag(
            'div',
            get_string('alsowillberemoved','local_cleanup', $file),
            [
                'style' => 'color:red; padding-top: 10px'
            ]
        ) .
        html_writer::table($table)
    ;
}

$form = new confirmation_form(null, $form_parameters);

if ($form->is_cancelled()) {
    redirect($redirect_url);
}

if ($form->is_confirmed()) {
    $fs = get_file_storage();
    $file = $fs->get_file_instance($file);

    $resource = $fs->get_file_system()->get_content_file_handle($file);
    $message = get_string(
        'fileremoved',
        'local_cleanup',
        [
            'name' => $file->get_filename(),
            'size' => $file->get_filesize() / 1024 / 1024,
        ]
    );
    $message_type = notification::SUCCESS;

    if ($resource === false) {
        // looks like the file is missing, so just removing the record.
        $DB->delete_records('files', ['contenthash' => $file->get_contenthash()]);
    } else {
        $uri = stream_get_meta_data($resource)['uri'];
        fclose($resource);

        if (unlink($uri)) {
            $DB->delete_records('files', ['contenthash' => $file->get_contenthash()]);
        } else {
            $message = get_string(
                'failtoremove',
                'local_cleanup',
                [
                    'name' => $file->get_filename()
                ]
            );
            $message_type = notification::ERROR;
        }
    }

    redirect($redirect_url, $message, 3, $message_type);
}

echo $OUTPUT->header();

$form->display();

echo $OUTPUT->footer();
