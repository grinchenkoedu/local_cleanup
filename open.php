<?php
/**
 * @global moodle_database $DB
 */

require_once(__DIR__ . '/../../config.php');

require_login();

if (!is_siteadmin()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Forbidden!');
}

$id = optional_param('id', 0, PARAM_INT);

$file = $DB->get_record('files', ['id' => $id], '*', MUST_EXIST);

if ($file->component === 'backup' && $file->filearea === 'course') {
    $url = new moodle_url('/backup/restorefile.php', ['contextid' => $file->contextid]);

    redirect($url);
}

$context = $DB->get_record('context', ['id' => $file->contextid], '*', MUST_EXIST);

if (CONTEXT_MODULE === (int)$context->contextlevel) {
    $module = $DB->get_record('course_modules', ['id' => $context->instanceid], '*', MUST_EXIST);

    if ($file->component === 'mod_resource') {
        $url = sprintf(
            '%s#module-%d',
            new moodle_url('/course/view.php', ['id' => $module->course]),
            $module->id
        );

        redirect($url);
    }

    redirect(
        new moodle_url(
            sprintf(
                '/mod/%s/view.php',
                str_replace('mod_', '', $file->component)
            ),
            [
                'id' => $module->id
            ]
        )
    );
}

throw new moodle_exception('unknowncontext', 'local_cleanup');
