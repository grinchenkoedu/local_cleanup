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

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/cleanup/batch_removal.php');
$PAGE->set_title(get_string('remove'));
$PAGE->set_heading(get_string('remove'));
$PAGE->set_pagelayout('default');

if (!is_siteadmin()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Forbidden!');
}

$component = optional_param('component', '', PARAM_TEXT);
$until = strtotime(optional_param('until', '', PARAM_TEXT));

if (
    $component !== 'assignsubmission_file'
    || $until > strtotime('-1 year')
) {
    header('HTTP/1.1 400 Bad request');
    exit('Provided time range or component name is not acceptable!');
}

$redirect_url = new moodle_url('/local/cleanup/statistics.php');

if (optional_param('confirm', false, PARAM_BOOL)) {
    $DB->delete_records_select(
        'files',
        'component = ? AND timecreated < ?',
        [
            $component,
            $until,
        ]
    );

    redirect($redirect_url, get_string('batchremovaldone', 'local_cleanup'), 3);
}

echo $OUTPUT->header();

echo $OUTPUT->confirm(
    sprintf(
        '%s %s %s %s?',
        get_string('remove'),
        mb_strtolower(get_string($component, 'local_cleanup')),
        get_string('to'),
        date('Y-m-d', $until)
    ),
    new moodle_url($PAGE->url, [
        'confirm' => 1,
        'component' => $component,
        'until' => optional_param('until', '', PARAM_TEXT),
    ]),
    $redirect_url
);

echo $OUTPUT->footer();
