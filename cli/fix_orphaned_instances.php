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
 * CLI script to remove activities left behind with no course module.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @var moodle_database $DB
 * @var stdClass $CFG
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_cleanup\output\mtrace_output;
use local_cleanup\repair\module_instances;

[$options, $unrecognised] = cli_get_params(
    [
        'help' => false,
        'execute' => false,
        'modules' => '',
        'days' => module_instances::DEFAULT_GRACE_DAYS,
        'courseid' => 0,
        'instanceid' => 0,
    ],
    [
        'h' => 'help',
        'e' => 'execute',
    ]
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help']) {
    cli_writeln(<<<'USAGE'
Remove activities that are left with no course module pointing at them.

An activity whose course module has gone is unreachable from the site, but not from cron: a
module's scheduled task selects straight from its own table, and the first row it cannot resolve
fails that task for the whole site. The symptom is a cron failure naming an instance id, such as
"mod_assign\task\cron_task ... Can not find data record in database table".

Reports by default and removes nothing. What it reports is what --execute would remove.

Where the activity's course still exists, the course module is put back, hidden and marked for
deletion, and core's own course_delete_module() removes the activity with its grades, files,
calendar events and context. Where the course has gone too, core cannot be used at all, so the
activity row and its calendar entries are deleted directly and the module's own child rows are
left behind - they reference nothing and break nothing.

This is a repair to run when something has gone wrong, not a scheduled job. Nothing here runs
from cron.

Usage:
    php fix_orphaned_instances.php                                  Report on every module.
    php fix_orphaned_instances.php --courseid=1234                  Report on one course.
    php fix_orphaned_instances.php --modules=assign --execute       Fix every stranded assignment.
    php fix_orphaned_instances.php --modules=assign --instanceid=126355 --execute

Options:
    -e, --execute       Actually remove what the report lists.
        --modules=x,y   Only these activity modules. Default: all of them.
        --courseid=N    Only activities of this course. Works for a deleted course too, because
                        the activity row keeps the id of the course it belonged to.
        --instanceid=N  Only this one activity. Requires exactly one --modules value, because
                        instance ids are only unique within a module.
        --days=N        Ignore activities modified in the last N days. Default: 7. This is what
                        keeps the repair off an activity that is mid-creation or mid-restore,
                        where having no course module yet is normal.
    -h, --help          Show this text.

USAGE);

    exit(0);
}

$modules = array_values(array_filter(array_map('trim', explode(',', (string)$options['modules']))));
$installed = core_component::get_plugin_list('mod');
$unknown = [];

foreach ($modules as $module) {
    if ($module !== clean_param($module, PARAM_PLUGIN) || !isset($installed[$module])) {
        $unknown[] = $module;
    }
}

if (!empty($unknown)) {
    cli_error('Not an installed activity module: ' . implode(', ', $unknown));
}

// Casting straight to int would turn a typo into a wider run than the operator asked for:
// --courseid=abc and a bare --courseid both come out as "no course named", which is every
// course, and --days=abc comes out as no grace period at all. Reject anything that is not
// already a whole number.
foreach (['days', 'courseid', 'instanceid'] as $number) {
    $given = $options[$number];

    if (!is_numeric($given) || (string)(int)$given !== (string)$given) {
        cli_error(sprintf('--%s needs a whole number, got: %s', $number, var_export($given, true)));
    }
}

$days = (int)$options['days'];
$courseid = (int)$options['courseid'];
$instanceid = (int)$options['instanceid'];

if ($days < 0 || $courseid < 0 || $instanceid < 0) {
    cli_error('--days, --courseid and --instanceid cannot be negative.');
}

if ($instanceid > 0 && count($modules) !== 1) {
    cli_error('--instanceid needs exactly one --modules value: instance ids are only unique within a module.');
}

$remove = (bool)$options['execute'];

if (!$remove) {
    cli_writeln('Dry run: nothing will be deleted. Pass --execute to act on this.');
}

if ($days === 0) {
    cli_writeln(
        'Warning: --days=0 removes the grace period. An activity being created or restored right '
        . 'now has no course module yet and will be treated as stranded.'
    );
}

$repair = new module_instances(
    $DB,
    $days,
    $modules,
    $courseid > 0 ? $courseid : null,
    $instanceid > 0 ? $instanceid : null
);

$output = new mtrace_output();
$result = $remove ? $repair->execute($output) : $repair->report($output);

$output->write_line(sprintf(
    '%s: %s',
    $remove ? 'Removed' : 'Would remove',
    $result->summarise()
));
