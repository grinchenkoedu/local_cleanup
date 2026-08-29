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

namespace local_cleanup;

use advanced_testcase;

/**
 * Tests for the settings migration.
 *
 * Getting the seeding condition backwards would silently start deleting student submissions on
 * upgrade, so the step is exercised rather than read.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class upgrade_test extends advanced_testcase {
    /**
     * Version of the plugin immediately before the settings moved.
     */
    const BEFORE_SETTINGS_MOVE = 2026082901;

    /**
     * Load the upgrade script, which is not autoloaded.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;

        // The upgrade script ends every step with upgrade_plugin_savepoint(), which lives in
        // upgradelib - a file the test bootstrap does not load.
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/cleanup/db/upgrade.php');

        parent::setUpBeforeClass();
    }

    /**
     * Every configured value moves from core config to the plugin's own namespace.
     *
     * @return void
     */
    public function test_settings_are_carried_across(): void {
        $this->resetAfterTest();

        set_config('cleanup_items_per_page', 25);
        set_config('cleanup_backup_timeout_days', 45);
        set_config('cleanup_draft_timeout', 14);
        set_config('cleanup_logs_timeout_days', 365);
        set_config('cleanup_component_files_days', 90);
        set_config('cleanup_grades_days', 400);
        set_config('cleanup_course_modules_days', 3);
        set_config('cleanup_run_autoremove', 1);

        $this->run_upgrade();

        $this->assertSame('25', get_config('local_cleanup', 'itemsperpage'));
        $this->assertSame('45', get_config('local_cleanup', 'backuplifetimedays'));
        $this->assertSame('14', get_config('local_cleanup', 'draftlifetimedays'));
        $this->assertSame('365', get_config('local_cleanup', 'logslifetimedays'));
        $this->assertSame('90', get_config('local_cleanup', 'componentfileslifetimedays'));
        $this->assertSame('400', get_config('local_cleanup', 'gradeslifetimedays'));
        $this->assertSame('3', get_config('local_cleanup', 'coursemoduleslifetimedays'));
        $this->assertSame('1', get_config('local_cleanup', 'autoremove'));
    }

    /**
     * The old core config keys are removed, not left behind as a second source of truth.
     *
     * @return void
     */
    public function test_old_core_settings_are_removed(): void {
        $this->resetAfterTest();

        set_config('cleanup_backup_timeout_days', 45);

        $this->run_upgrade();

        $this->assertFalse(get_config(null, 'cleanup_backup_timeout_days'));
    }

    /**
     * A site that was already deleting component files keeps doing so.
     *
     * The set of components used to be hardcoded, so an upgrading site with automatic removal
     * enabled was relying on that pair and must not silently stop.
     *
     * @return void
     */
    public function test_a_site_using_autoremove_keeps_the_old_components(): void {
        $this->resetAfterTest();

        set_config('cleanup_run_autoremove', 1);

        $this->run_upgrade();

        $this->assertSame(
            'backup,assignsubmission_file',
            get_config('local_cleanup', 'componentfiles')
        );
    }

    /**
     * A site with automatic removal switched off gets the safe default instead.
     *
     * @return void
     */
    public function test_a_site_not_using_autoremove_gets_the_empty_default(): void {
        $this->resetAfterTest();

        set_config('cleanup_run_autoremove', 0);

        $this->run_upgrade();

        $this->assertSame(
            '',
            get_config('local_cleanup', 'componentfiles'),
            'Nothing may be deleted per component until an administrator opts in.'
        );
    }

    /**
     * A site that never configured the plugin gets the safe default too.
     *
     * @return void
     */
    public function test_an_unconfigured_site_gets_the_empty_default(): void {
        $this->resetAfterTest();

        $this->run_upgrade();

        $this->assertSame('', get_config('local_cleanup', 'componentfiles'));
    }

    /**
     * Run the upgrade step under test, from the version just before it.
     *
     * @return void
     */
    private function run_upgrade(): void {
        // The savepoint refuses to move a plugin backwards, so the recorded version has to sit
        // below the one this step raises it to.
        set_config('version', self::BEFORE_SETTINGS_MOVE, 'local_cleanup');

        xmldb_local_cleanup_upgrade(self::BEFORE_SETTINGS_MOVE);
    }
}
