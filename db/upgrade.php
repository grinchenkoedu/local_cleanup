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
 *
 * @param int $oldversion The old version number
 *
 * @return bool Success status
 *
 * @throws coding_exception
 * @throws ddl_exception
 */
function xmldb_local_cleanup_upgrade($oldversion = 0) {
    global $DB;

    $manager = $DB->get_manager();

    if ($oldversion < 2023061000) {
        $table = new xmldb_table('files');
        $manager->add_index(
            $table,
            new xmldb_index('component', XMLDB_INDEX_NOTUNIQUE, ['component'])
        );
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

    return true;
}
