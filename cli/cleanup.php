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
 * CLI script to report on, or perform, the clean-up.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @var stdClass $CFG
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_cleanup\output\mtrace_output;
use local_cleanup\step_factory;

[$options, $unrecognised] = cli_get_params(
    [
        'help' => false,
        'execute' => false,
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
Report on the clean-up, or perform it.

Reports by default and removes nothing. What it reports is exactly what --execute would remove,
because both come from the same definition of each step, so the numbers can be trusted before
you act on them.

What is in scope comes from the plugin's settings, not from this script. In particular no
component loses files until an administrator has ticked it in "Components to clean up", so a
freshly installed site reports nothing to remove no matter how this is run.

Usage:
    php cleanup.php                Report what would be removed. Changes nothing.
    php cleanup.php --execute      Remove it.
    php cleanup.php --help         Show this text.

Options:
    -e, --execute   Actually remove what the report lists.
    -h, --help      Show this text.

USAGE);

    exit(0);
}

$remove = (bool)$options['execute'];

if (!$remove) {
    cli_writeln('Dry run: nothing will be deleted. Pass --execute to act on this.');
}

step_factory::run_all(new mtrace_output(), $remove);
