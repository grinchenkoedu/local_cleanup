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

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/cleanup/remove.php');
$PAGE->set_title(get_string('remove'));
$PAGE->set_heading(get_string('remove'));
$PAGE->set_pagelayout('default');

require_login();

$id = optional_param('id', 0, PARAM_INT);
$file = $DB->get_record('files', ['id' => $id], '*', MUST_EXIST);

if (!is_siteadmin()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Forbidden!');
}

$redirect_url = new moodle_url(optional_param('redirect', '/local/cleanup/files.php', PARAM_TEXT));

if (optional_param('confirm', false, PARAM_BOOL)) {
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

    if (!$resource) {
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

echo $OUTPUT->confirm(
    sprintf(
        '%s %s <b>%s</b>, %s %s?',
        get_string('remove'),
        mb_strtolower(get_string('file')),
        $file->filename,
        round($file->filesize / 1024 / 1024, 2),
        get_string('sizemb')
    ),
    new moodle_url($PAGE->url, [
        'id' => $id,
        'confirm' => 1,
    ]),
    $redirect_url
);

echo $OUTPUT->footer();
