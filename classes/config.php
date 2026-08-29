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

use local_cleanup\step\component_files_cleanup;
use local_cleanup\step\course_modules_cleanup;
use local_cleanup\step\files_checkout;
use local_cleanup\step\grades_cleanup;
use local_cleanup\step\logs_cleanup;

/**
 * Typed access to the plugin's settings.
 *
 * Settings live under the plugin's own name rather than in core config, and nothing outside
 * this class reads them. That keeps the setting names in one place and gives every caller a
 * value of the right type instead of a string.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {
    /**
     * The plugin these settings belong to.
     */
    const COMPONENT = 'local_cleanup';

    /**
     * Components the administrator may choose to clean up, and whether each is recommended.
     *
     * Deliberately a fixed list. A free-text field would let someone enter "user" and delete
     * every profile picture on the site, or "core" and take out rather more than that.
     */
    const CLEANABLE_COMPONENTS = [
        'backup' => true,
        'assignsubmission_file' => false,
        'assignfeedback_file' => false,
        'tool_recyclebin' => false,
    ];

    /**
     * Number of rows shown per page in the files report.
     *
     * @return int Rows per page
     */
    public static function items_per_page(): int {
        return (int)(self::get('itemsperpage') ?: finder::LIMIT_DEFAULT);
    }

    /**
     * Whether the scheduled task is allowed to delete anything at all.
     *
     * @return bool True when automatic removal is enabled
     */
    public static function autoremove_enabled(): bool {
        return (bool)self::get('autoremove');
    }

    /**
     * Components whose files the administrator has opted in to cleaning up.
     *
     * Empty by default: enabling automatic removal must not, on its own, start deleting
     * anybody's files.
     *
     * @return string[] Component names, possibly empty
     */
    public static function component_files(): array {
        $chosen = (string)self::get('componentfiles');

        if ($chosen === '') {
            return [];
        }

        return array_values(array_intersect(
            array_map('trim', explode(',', $chosen)),
            array_keys(self::CLEANABLE_COMPONENTS)
        ));
    }

    /**
     * Days to keep backup files.
     *
     * @return int Days
     */
    public static function backup_lifetime_days(): int {
        return self::days('backuplifetimedays', files_checkout::DEFAULT_TIMEOUT_DAYS);
    }

    /**
     * Days to keep draft files.
     *
     * @return int Days
     */
    public static function draft_lifetime_days(): int {
        return self::days('draftlifetimedays', files_checkout::DEFAULT_TIMEOUT_DAYS);
    }

    /**
     * Days to keep log entries.
     *
     * @return int Days
     */
    public static function logs_lifetime_days(): int {
        return self::days('logslifetimedays', logs_cleanup::DEFAULT_LIFETIME_DAYS);
    }

    /**
     * Days to keep files of the chosen components.
     *
     * @return int Days
     */
    public static function component_files_lifetime_days(): int {
        return self::days('componentfileslifetimedays', component_files_cleanup::DEFAULT_LIFETIME_DAYS);
    }

    /**
     * Days to keep grade history.
     *
     * @return int Days
     */
    public static function grades_lifetime_days(): int {
        return self::days('gradeslifetimedays', grades_cleanup::DEFAULT_LIFETIME_DAYS);
    }

    /**
     * Days to leave a failed course module deletion before forcing it.
     *
     * @return int Days
     */
    public static function course_modules_lifetime_days(): int {
        return self::days('coursemoduleslifetimedays', course_modules_cleanup::DEFAULT_LIFETIME_DAYS);
    }

    /**
     * Read a day count, falling back to the default when it is unset or not a positive number.
     *
     * @param string $name Setting name
     * @param int $default Value to use when the setting is unusable
     * @return int Days
     */
    private static function days(string $name, int $default): int {
        $value = (int)self::get($name);

        return $value > 0 ? $value : $default;
    }

    /**
     * Read one setting.
     *
     * @param string $name Setting name, without the component prefix
     * @return mixed The stored value, or false when it has never been set
     */
    private static function get(string $name) {
        return get_config(self::COMPONENT, $name);
    }
}
