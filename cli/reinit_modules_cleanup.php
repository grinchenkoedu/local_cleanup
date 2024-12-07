<?php
/**
 * @global moodle_database $DB
 * @global object $CFG
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

if (!in_array($argv[1] ?? null, ['--force', '-f'])) {
    mtrace('This script will reinitialize course modules clean-up and delete existing modules removal adhoc tasks.');
    mtrace('To start the process, run this script with -f (--force) option e.g. "php reinit_modules_cleanup.php -f"');

    exit(1);
}

$admin = get_admin();

if (empty($admin)) {
    mtrace('Admin user not found. Exiting...');

    exit(1);
}

mtrace(sprintf('Course modules clean-up reinitialization started at %s', date(DATE_ATOM)));

mtrace('Removing existing modules removal adhoc tasks... ', null);
$DB->delete_records('task_adhoc', ['classname' => '\core_course\task\course_delete_modules']);
mtrace("OK");

mtrace('Selecting courses with modules for removal... ', null);
$courses_ids = $DB->get_fieldset_sql("SELECT course FROM {course_modules} WHERE deletioninprogress = 1 GROUP BY course");
mtrace("OK");

foreach ($courses_ids as $id) {
    mtrace("Selecting course modules for removal in course $id... ", null);

    $course_modules = $DB->get_records(
        'course_modules',
        ['course' => $id, 'deletioninprogress' => 1],
        '',
        'id'
    );

    $removaltask = new \core_course\task\course_delete_modules();
    $data = array(
        'cms' => $course_modules,
        'userid' => $admin->id,
        'realuserid' => $admin->id,
    );
    $removaltask->set_custom_data($data);
    \core\task\manager::queue_adhoc_task($removaltask);

    mtrace("OK");
}

mtrace(sprintf('Course modules clean-up reinitialization finished at %s', date(DATE_ATOM)));
