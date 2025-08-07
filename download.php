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

if (!is_siteadmin()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Forbidden!');
}

$filepath = optional_param('path', 0, PARAM_TEXT);
$fileid = optional_param('id', 0, PARAM_INT);

if (!empty($filepath)) {
    $absolute = $CFG->dataroot . DIRECTORY_SEPARATOR . $filepath;

    if (!is_readable($absolute)) {
        header('HTTP/1.1 404 Not found');
        exit('Not found!');
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
