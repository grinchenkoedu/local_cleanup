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
 * File removal handler for the cleanup plugin.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @phpcs:ignore moodle.Commenting.ValidTags.Invalid
 * @var stdClass $CFG
 * @var stdClass $USER
 * @var moodle_page $PAGE
 * @var moodle_database $DB
 * @var renderer_base $OUTPUT
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');

use core\notification;

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/cleanup/remove.php');
$PAGE->set_title(get_string('remove'));
$PAGE->set_heading(get_string('remove'));
$PAGE->set_pagelayout('default');

require_login();

if (!is_siteadmin()) {
    header('HTTP/1.1 403 Forbidden');
    exit('Forbidden!');
}

$id = optional_param('id', 0, PARAM_INT);
$file = $DB->get_record('files', ['id' => $id], '*', MUST_EXIST);

$redirecturl = new moodle_url(optional_param('redirect', '/local/cleanup/files.php', PARAM_TEXT));

if (optional_param('confirm', false, PARAM_BOOL)) {
    $fs = get_file_storage();
    $file = $fs->get_file_instance($file);

    $resource = $fs->get_file_system()->get_content_file_handle($file);
    $message = get_string(
        'fileremoved',
        'local_cleanup',
        [
            'name' => $file->get_filename(),
            'size' => $file->get_filesize() / 1024 / 1024,
        ]
    );
    $messagetype = notification::SUCCESS;

    if (!$resource) {
        // Looks like the file is missing, so just removing the record.
        $DB->delete_records('files', ['contenthash' => $file->get_contenthash()]);
    } else {
        $uri = stream_get_meta_data($resource)['uri'];
        fclose($resource);

        if (unlink($uri)) {
            $DB->delete_records('files', ['contenthash' => $file->get_contenthash()]);
        } else {
            $message = get_string(
                'failtoremove',
                'local_cleanup',
                [
                    'name' => $file->get_filename(),
                ]
            );
            $messagetype = notification::ERROR;
        }
    }

    redirect($redirecturl, $message, 3, $messagetype);
}

echo $OUTPUT->header();

echo $OUTPUT->confirm(
    sprintf(
        '%s %s <b>%s</b>, %s %s?',
        get_string('remove'),
        mb_strtolower(get_string('file')),
        $file->filename,
        round($file->filesize / 1024 / 1024, 2),
        get_string('sizemb')
    ),
    new moodle_url($PAGE->url, [
        'id' => $id,
        'confirm' => 1,
    ]),
    $redirecturl
);

echo $OUTPUT->footer();
