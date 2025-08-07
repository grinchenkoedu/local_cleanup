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
 * CLI script to reinitialize course module cleanup tasks.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @var moodle_database $DB
 * @var object $CFG
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
$coursesids = $DB->get_fieldset_sql("SELECT course FROM {course_modules} WHERE deletioninprogress = 1 GROUP BY course");
mtrace("OK");

foreach ($coursesids as $id) {
    mtrace("Selecting course modules for removal in course $id... ", null);

    $coursemodules = $DB->get_records(
        'course_modules',
        ['course' => $id, 'deletioninprogress' => 1],
        '',
        'id'
    );

    $removaltask = new \core_course\task\course_delete_modules();
    $data = [
        'cms' => $coursemodules,
        'userid' => $admin->id,
        'realuserid' => $admin->id,
    ];
    $removaltask->set_custom_data($data);
    \core\task\manager::queue_adhoc_task($removaltask);

    mtrace("OK");
}

mtrace(sprintf('Course modules clean-up reinitialization finished at %s', date(DATE_ATOM)));
