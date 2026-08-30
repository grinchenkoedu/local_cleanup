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

$string['any'] = 'Any';
$string['assignsubmission_file'] = 'Uploaded students\' submissions';
$string['atleastsize'] = 'At least';
$string['autoremove'] = 'Auto remove outdated files';
$string['autoremovedesc'] = 'Remove outdated files found in the filesystem on clean-up';
$string['awaitingscan'] = 'Not yet dated';
$string['awaitingsecondscan'] = 'awaiting a second scan';
$string['backup'] = 'Backup copies';
$string['backuplifetime'] = 'Backup files lifetime';
$string['backuplifetimedesc'] = 'Number of days to keep backups';
$string['cleanup:deletefiles'] = 'Delete files found by the clean-up reports';
$string['cleanup:view'] = 'View the clean-up reports';
$string['cleanupnotscheduled'] = 'not scheduled';
$string['component_assignfeedback_file'] = 'Assignment feedback files';
$string['component_assignsubmission_file'] = 'Assignment file submissions';
$string['component_backup'] = 'Course and activity backups';
$string['component_tool_recyclebin'] = 'Recycle bin contents';
$string['componentfiles'] = 'Components to clean up';
$string['componentfilesdesc'] = 'Files belonging to the ticked components are deleted once they are older than the lifetime below. Nothing is ticked by default, so enabling automatic removal on its own deletes no component files. Backups are marked recommended because they can be regenerated; the others hold work somebody produced, and removing their files leaves the activity\'s own records behind until the file API migration lands.';
$string['componentfileslifetime'] = 'Component files lifetime';
$string['componentfileslifetimedesc'] = 'Number of days to keep component files';
$string['componentrecommended'] = '(recommended)';
$string['coursemoduleslifetime'] = 'Course modules lifetime';
$string['coursemoduleslifetimedesc'] = 'Number of days to keep orphaned course modules';
$string['deletedusers'] = 'Owned by a deleted user';
$string['draftlifetime'] = 'Draft files lifetime';
$string['draftlifetimedesc'] = 'Number of days to keep draft files';
$string['event_file_deleted'] = 'File deleted';
$string['failtoremove'] = 'Failed to remove file "{$a->name}"';
$string['fileremoved'] = 'File "{$a->name}" removed, {$a->size}Mb cleaned';
$string['filesfound'] = 'Files found';
$string['firstseen'] = 'First seen unlinked';
$string['ghostfiles'] = 'Unlinked files';
$string['ghostgrace'] = 'Unlinked file grace period';
$string['ghostgracedesc'] = 'Days that must pass between the two scans that agree a file is unlinked before it is removed. A file seen only once, or seen twice in quick succession, is left alone: content uploaded between scans can deduplicate onto a hash a previous scan recorded as unlinked. Removed files are moved to the trash directory, so Moodle\'s own trash clean-up gives a further recovery window.';
$string['gradeslifetime'] = 'Grades history lifetime';
$string['gradeslifetimedesc'] = 'Number of days to keep grades history';
$string['itemsperpage'] = 'Items per page';
$string['itemsperpagedesc'] = 'Affects performance';
$string['logslifetime'] = 'Logs lifetime';
$string['logslifetimedesc'] = 'Number of days to keep logs';
$string['maxrecordsperrun'] = 'Maximum records removed per run';
$string['maxrecordsperrundesc'] = 'Stops any one clean-up step removing more than this many records in a single run, so a long backlog is worked through over several nights rather than overrunning the cron window. Zero means no limit, which is the default. Whatever is left is picked up next time.';
$string['mimetype'] = 'Type';
$string['nextcleanup'] = 'Next clean-up: {$a}';
$string['nothingtoshow'] = 'Nothing to show';
$string['pluginname'] = 'Clean-up';
$string['settingspage'] = 'Clean-up settings';
$string['taskcleanup'] = 'Database and disk clean-up';
$string['taskscan'] = 'Scan for unlinked files';
$string['totalsize'] = 'Total size';
$string['unknowncontext'] = 'This file belongs to a context that cannot be opened directly.';
