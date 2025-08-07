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
 * File viewer for the cleanup plugin.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @var moodle_database $DB
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
                'id' => $module->id,
            ]
        )
    );
}

throw new moodle_exception('unknowncontext', 'local_cleanup');
