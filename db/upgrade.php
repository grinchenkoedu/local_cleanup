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
 * Database upgrade script for local_cleanup plugin.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the plugin's database schema.
 *
 * @param int $oldversion The old version number
 * @return bool Success status
 * @throws coding_exception
 * @throws ddl_exception
 */
function xmldb_local_cleanup_upgrade($oldversion = 0) {
    global $CFG, $DB;

    $manager = $DB->get_manager();

    if ($oldversion < 2023061000) {
        // This step originally also added a single-column "component" index. It was redundant
        // with core's own component-filearea-contextid-itemid, which serves the same queries by
        // leftmost prefix, and the 2026082800 step below removes it. Creating it here would
        // mean building an index over the whole {files} table only to drop it moments later in
        // the same upgrade run, which on a large site is slow and pointless, so it is no longer
        // created. Sites that already ran this step still have it, and still get it dropped.
        $table = new xmldb_table('files');
        $manager->add_index(
            $table,
            new xmldb_index('component_filesize', XMLDB_INDEX_NOTUNIQUE, ['component', 'filesize'])
        );
        $manager->add_index(
            $table,
            new xmldb_index('component_timecreated', XMLDB_INDEX_NOTUNIQUE, ['component', 'timecreated'])
        );

        upgrade_plugin_savepoint(true, 2023061000, 'local', 'cleanup');
    }

    if ($oldversion < 2025080700) {
        $oldtable = new xmldb_table('cleanup');
        $newtable = new xmldb_table('local_cleanup_files');

        if ($manager->table_exists($oldtable) && !$manager->table_exists($newtable)) {
            $manager->rename_table($oldtable, 'local_cleanup_files');
        }

        upgrade_plugin_savepoint(true, 2025080700, 'local', 'cleanup');
    }

    if ($oldversion < 2026082800) {
        // A plugin must not own indexes on a core table: check_database_schema() defaults to
        // reporting extra indexes, so every site running this plugin sees "Unexpected index".
        //
        // This only has work to do on a site that ran the 2023061000 step back when it still
        // created the index; anywhere else index_exists() is false and the step is skipped.
        //
        // component_filesize and component_timecreated are deliberately left in place until
        // the plugin queries its own indexed table instead. See README.md.
        $table = new xmldb_table('files');
        $index = new xmldb_index('component', XMLDB_INDEX_NOTUNIQUE, ['component']);

        if ($manager->index_exists($table, $index)) {
            $manager->drop_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026082800, 'local', 'cleanup');
    }

    if ($oldversion < 2026082902) {
        // Settings move from core config to the plugin's own namespace. Carry each value
        // across so an upgraded site keeps the configuration it already had.
        $renames = [
            'cleanup_items_per_page' => 'itemsperpage',
            'cleanup_backup_timeout_days' => 'backuplifetimedays',
            'cleanup_draft_timeout' => 'draftlifetimedays',
            'cleanup_logs_timeout_days' => 'logslifetimedays',
            'cleanup_component_files_days' => 'componentfileslifetimedays',
            'cleanup_grades_days' => 'gradeslifetimedays',
            'cleanup_course_modules_days' => 'coursemoduleslifetimedays',
            'cleanup_run_autoremove' => 'autoremove',
        ];

        $wasautoremoveon = !empty($CFG->cleanup_run_autoremove);

        foreach ($renames as $old => $new) {
            if (isset($CFG->$old)) {
                set_config($new, $CFG->$old, 'local_cleanup');
                unset_config($old);
            }
        }

        // The set of components to clean up used to be hardcoded, and now it is a setting
        // that starts empty. A site already running automatic removal was relying on the old
        // pair, so keep it; anywhere else the safe default stands and nothing is deleted per
        // component until somebody ticks a box.
        set_config('componentfiles', $wasautoremoveon ? 'backup,assignsubmission_file' : '', 'local_cleanup');

        upgrade_plugin_savepoint(true, 2026082902, 'local', 'cleanup');
    }

    if ($oldversion < 2026082906) {
        $table = new xmldb_table('local_cleanup_files');

        // When a scan first found the file unreferenced, and when it last did. An unlinked
        // file is only removed once two scans a grace period apart have both seen it, so a
        // file that reappears in between is never destroyed on the strength of one sighting.
        $confirmed = new xmldb_field(
            'timeconfirmed',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'size'
        );

        if (!$manager->field_exists($table, $confirmed)) {
            $manager->add_field($table, $confirmed);
        }

        $scanned = new xmldb_field(
            'timescanned',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'timeconfirmed'
        );

        if (!$manager->field_exists($table, $scanned)) {
            $manager->add_field($table, $scanned);
        }

        // The scan looks a row up by path for every file it walks.
        $index = new xmldb_index('path', XMLDB_INDEX_NOTUNIQUE, ['path']);

        if (!$manager->index_exists($table, $index)) {
            $manager->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026082906, 'local', 'cleanup');
    }

    return true;
}
