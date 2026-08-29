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
 * Tests for the settings accessor.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_cleanup\config
 */
final class config_test extends advanced_testcase {
    /**
     * With nothing configured, no component is cleaned up.
     *
     * This is the whole point of the default: turning automatic removal on must not start
     * deleting anybody's files until somebody names a component.
     *
     * @return void
     */
    public function test_no_components_by_default(): void {
        $this->resetAfterTest();

        $this->assertSame([], config::component_files());
    }

    /**
     * The chosen components are returned in the order they were stored.
     *
     * @return void
     */
    public function test_chosen_components_are_returned(): void {
        $this->resetAfterTest();

        set_config('componentfiles', 'backup,assignsubmission_file', 'local_cleanup');

        $this->assertSame(['backup', 'assignsubmission_file'], config::component_files());
    }

    /**
     * Anything not on the fixed list is discarded, whatever is in the database.
     *
     * @return void
     */
    public function test_unknown_components_are_ignored(): void {
        $this->resetAfterTest();

        set_config('componentfiles', 'backup,user,core', 'local_cleanup');

        $this->assertSame(
            ['backup'],
            config::component_files(),
            'Only components on the fixed list may ever be cleaned up.'
        );
    }

    /**
     * Automatic removal is off until it is switched on.
     *
     * @return void
     */
    public function test_autoremove_is_off_by_default(): void {
        $this->resetAfterTest();

        $this->assertFalse(config::autoremove_enabled());

        set_config('autoremove', 1, 'local_cleanup');

        $this->assertTrue(config::autoremove_enabled());
    }

    /**
     * Every lifetime accessor reads its own setting.
     *
     * They share one code path, so this guards against a copy-paste error pointing two
     * accessors at the same key.
     *
     * @return void
     */
    public function test_each_lifetime_reads_its_own_setting(): void {
        $this->resetAfterTest();

        $settings = [
            'backuplifetimedays' => 11,
            'draftlifetimedays' => 22,
            'logslifetimedays' => 33,
            'componentfileslifetimedays' => 44,
            'gradeslifetimedays' => 55,
            'coursemoduleslifetimedays' => 66,
        ];

        foreach ($settings as $name => $value) {
            set_config($name, $value, 'local_cleanup');
        }

        $this->assertSame(11, config::backup_lifetime_days());
        $this->assertSame(22, config::draft_lifetime_days());
        $this->assertSame(33, config::logs_lifetime_days());
        $this->assertSame(44, config::component_files_lifetime_days());
        $this->assertSame(55, config::grades_lifetime_days());
        $this->assertSame(66, config::course_modules_lifetime_days());
    }

    /**
     * The page size falls back when unset.
     *
     * @return void
     */
    public function test_items_per_page(): void {
        $this->resetAfterTest();

        $this->assertSame(finder::LIMIT_DEFAULT, config::items_per_page());

        set_config('itemsperpage', 200, 'local_cleanup');

        $this->assertSame(200, config::items_per_page());
    }

    /**
     * Lifetimes fall back to the step defaults when unset or nonsensical.
     *
     * @return void
     */
    public function test_lifetimes_fall_back_to_defaults(): void {
        $this->resetAfterTest();

        $this->assertSame(30, config::backup_lifetime_days());

        set_config('backuplifetimedays', 0, 'local_cleanup');

        $this->assertSame(
            30,
            config::backup_lifetime_days(),
            'Zero days would mean deleting everything, so it falls back instead.'
        );

        set_config('backuplifetimedays', 90, 'local_cleanup');

        $this->assertSame(90, config::backup_lifetime_days());
    }
}
