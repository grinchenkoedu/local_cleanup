<?php
/**
 * @global moodle_database $DB
 * @global stdClass $CFG
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

if (!is_siteadmin()) {
    header('HTTP/1.1 403 Forbidden');
    exit();
}

$file_path = optional_param('path', 0, PARAM_TEXT);
$file_id = optional_param('id', 0, PARAM_INT);

if (!empty($file_path)) {
    $absolute = $CFG->dataroot . DIRECTORY_SEPARATOR . $file_path;

    if (!is_readable($absolute)) {
        header('HTTP/1.1 404 Not found');
        exit();
    }

    send_file($absolute, basename($absolute));
}

$file = $DB->get_record('files', ['id' => $file_id], '*', MUST_EXIST);

$url = moodle_url::make_pluginfile_url(
    $file->contextid,
    $file->component,
    $file->filearea,
    $file->itemid,
    $file->filepath,
    $file->filename,
    true
);

redirect($url, '', 0);
