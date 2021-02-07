<?php

defined('MOODLE_INTERNAL') || die('Direct access to this script is forbidden.');

/**
 * @param int $oldversion
 *
 * @return bool
 *
 * @throws coding_exception
 * @throws ddl_exception
 */
function xmldb_local_cleanup_upgrade($oldversion = 0)
{
    global $DB;

    $manager = $DB->get_manager();

    if ($oldversion < 2020020701) {
        $cleanup_table = new xmldb_table('cleanup');
        $cleanup_table->add_field(
            'id',
            XMLDB_TYPE_INTEGER,
            '10',
            true,
            XMLDB_NOTNULL,
            XMLDB_SEQUENCE
        );

        $cleanup_table->add_field('path', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $cleanup_table->add_field('mime', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $cleanup_table->add_field('size', XMLDB_TYPE_INTEGER, '10', true, XMLDB_NOTNULL);

        $primary = new xmldb_key('primary');
        $primary->set_attributes(XMLDB_KEY_PRIMARY, ['id']);
        $cleanup_table->addKey($primary);

        $manager->create_table($cleanup_table);
    }

    return true;
}
