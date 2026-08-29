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
 * File download handler for the cleanup plugin.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @var moodle_database $DB
 * @var stdClass $CFG
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

require_login();
require_capability('local/cleanup:view', context_system::instance());

$filepath = optional_param('path', '', PARAM_PATH);
$fileid = optional_param('id', 0, PARAM_INT);

if (!empty($filepath)) {
    // Unlinked files are served straight off disk, so the resolved path must be proven to sit
    // inside the file pool. PARAM_PATH already strips "..", the realpath check is what
    // guarantees it: a symlink or any other escape resolves outside $filedirroot and is refused.
    $filedirroot = realpath($CFG->dataroot . DIRECTORY_SEPARATOR . 'filedir');
    $absolute = realpath($CFG->dataroot . DIRECTORY_SEPARATOR . $filepath);

    if (
        $filedirroot === false
        || $absolute === false
        || strpos($absolute, $filedirroot . DIRECTORY_SEPARATOR) !== 0
        || !is_file($absolute)
        || !is_readable($absolute)
    ) {
        throw new moodle_exception('filenotfound', 'error');
    }

    send_file($absolute, basename($absolute));
}

$file = $DB->get_record('files', ['id' => $fileid], '*', MUST_EXIST);

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
