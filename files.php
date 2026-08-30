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
 * Files management page for cleanup plugin.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @var moodle_page $PAGE
 * @var moodle_database $DB
 * @var stdClass $CFG
 * @var renderer_base $OUTPUT
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');

use local_cleanup\config;
use local_cleanup\finder;
use local_cleanup\form\filter_form;
use local_cleanup\table\files_table;

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/cleanup/files.php');
$PAGE->set_title(get_string('files'));
$PAGE->set_heading(get_string('files'));
$PAGE->set_pagelayout('admin');

require_login();
require_capability('local/cleanup:view', context_system::instance());

$defaults = [
    'filesize' => 50,
    'name_like' => '',
    'user_like' => '',
    'component' => '',
    'user_deleted' => 0,
];

$filter = [
    'filesize' => optional_param('filesize', $defaults['filesize'], PARAM_INT),
    'name_like' => optional_param('name_like', $defaults['name_like'], PARAM_TEXT),
    'user_like' => optional_param('user_like', $defaults['user_like'], PARAM_TEXT),
    'component' => optional_param('component', $defaults['component'], PARAM_COMPONENT),
    'user_deleted' => optional_param('user_deleted', $defaults['user_deleted'], PARAM_BOOL),
];

$finder = new finder($DB);
$filterform = new filter_form(null, $filter + ['components' => $finder->get_components()]);

if ($filterform->is_cancelled()) {
    redirect($PAGE->url);
}

// The filter travels in the URL so that sorting, paging and the delete round trip all keep it.
// Only what differs from the defaults goes along: a size of 0 means "any" and has to survive,
// so this cannot simply drop falsy values.
$baseurl = new moodle_url($PAGE->url, array_diff_assoc($filter, $defaults));

$table = new files_table(
    'local_cleanup_files_report',
    $finder,
    $filter,
    $baseurl,
    has_capability('local/cleanup:deletefiles', context_system::instance())
);
$table->is_downloading(
    optional_param('download', '', PARAM_ALPHA),
    'local_cleanup_files',
    get_string('files')
);

if (!$table->is_downloading()) {
    echo $OUTPUT->header();

    $filterform->display();
}

$table->out(config::items_per_page(), true);

if (!$table->is_downloading()) {
    echo $OUTPUT->footer();
}
