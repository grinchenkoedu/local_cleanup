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

$id = required_param('id', PARAM_INT);

$fs = get_file_storage();
$file = $fs->get_file_by_id($id);

if (!$file) {
    throw new moodle_exception('filenotfound', 'error');
}

// Only a local URL is accepted, so the confirmation cannot be used to bounce off site.
// PARAM_LOCALURL also passes bare relative URLs such as "files.php". moodle_url only resolves
// a value against wwwroot when it starts with a slash, so a relative one stays relative and
// out_as_local_url() below would reject it. Accept only the two forms that survive that.
$redirect = optional_param('redirect', '', PARAM_LOCALURL);

if (strpos($redirect, '/') !== 0 && strpos($redirect, $CFG->wwwroot) !== 0) {
    $redirect = '/local/cleanup/files.php';
}

$redirecturl = new moodle_url($redirect);

$filename = $file->get_filename();
$filesize = $file->get_filesize();

if (optional_param('confirm', false, PARAM_BOOL)) {
    require_sesskey();

    try {
        // Note: stored_file::delete() removes this record only. The content is kept when
        // another record still references the same contenthash, and moved to the trash
        // directory otherwise, so core's trash clean-up task provides a recovery window.
        $file->delete();

        $message = get_string(
            'fileremoved',
            'local_cleanup',
            [
                'name' => $filename,
                'size' => round($filesize / 1024 / 1024, 2),
            ]
        );
        $messagetype = notification::SUCCESS;
    } catch (moodle_exception $e) {
        // Deliberately narrow: dml_exception and file_exception both extend moodle_exception,
        // so every failure the File API raises is handled. Anything else - an SDK exception
        // from an alternative_file_system_class, say - is left to propagate rather than being
        // reported as an ordinary removal failure.
        debugging($e->getMessage(), DEBUG_DEVELOPER);

        $message = get_string(
            'failtoremove',
            'local_cleanup',
            [
                'name' => $filename,
            ]
        );
        $messagetype = notification::ERROR;
    }

    redirect($redirecturl, $message, 3, $messagetype);
}

echo $OUTPUT->header();

echo $OUTPUT->confirm(
    sprintf(
        '%s %s <b>%s</b>, %s %s?',
        get_string('remove'),
        mb_strtolower(get_string('file')),
        s($filename),
        round($filesize / 1024 / 1024, 2),
        get_string('sizemb')
    ),
    new moodle_url($PAGE->url, [
        'id' => $id,
        'confirm' => 1,
        'redirect' => $redirecturl->out_as_local_url(false),
        'sesskey' => sesskey(),
    ]),
    $redirecturl
);

echo $OUTPUT->footer();
