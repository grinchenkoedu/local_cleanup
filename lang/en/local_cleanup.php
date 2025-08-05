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
 * English language strings for local_cleanup plugin.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['assignsubmission_file'] = 'Uploaded students\' submissions';
$string['autoremove'] = 'Auto remove outdated files';
$string['autoremovedesc'] = 'Remove outdated files found in the filesystem on clean-up';
$string['backup'] = 'Backup copies';
$string['backuplifetime'] = 'Backup files lifetime';
$string['backuplifetimedesc'] = 'Number of days to keep backups';
$string['batchremovaldone'] = 'Batch removal completed';
$string['componentfileslifetime'] = 'Component files lifetime';
$string['componentfileslifetimedesc'] = 'Number of days to keep component files';
$string['coursemoduleslifetime'] = 'Course modules lifetime';
$string['coursemoduleslifetimedesc'] = 'Number of days to keep orphaned course modules';
$string['directorylifetime'] = 'Directory files lifetime';
$string['directorylifetimedesc'] = 'Number of days to keep directory files';
$string['draftlifetime'] = 'Draft files lifetime';
$string['draftlifetimedesc'] = 'Number of days to keep draft files';
$string['failtoremove'] = 'Failed to remove file "{$a->name}"';
$string['fileremoved'] = 'File "{$a->name}" removed, {$a->size}Mb cleaned';
$string['files_total'] = 'Files total';
$string['ghostfiles'] = 'Unlinked files';
$string['ghosttotalheader'] = 'Total files found: {$a->files}, total size: {$a->size}Gb, next clean-up: {$a->cleanup_date}';
$string['gradeslifetime'] = 'Grades history lifetime';
$string['gradeslifetimedesc'] = 'Number of days to keep grades history';
$string['itemsperpage'] = 'Items per page';
$string['itemsperpagedesc'] = 'Affects performance';
$string['logslifetime'] = 'Logs lifetime';
$string['logslifetimedesc'] = 'Number of days to keep logs';
$string['nothingtoshow'] = 'Nothing to show';
$string['pluginname'] = 'Clean-up';
$string['removeconfirm'] = 'You about to remove file "{$a->name}" with id "{$a->id}". Are you sure?';
$string['settingspage'] = 'Clean-up settings';
$string['title'] = 'Clean-up';
$string['userfiles'] = 'Users files';
