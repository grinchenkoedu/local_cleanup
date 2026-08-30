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

namespace local_cleanup\privacy;

use core_privacy\local\metadata\null_provider;

/**
 * Privacy provider for the clean-up plugin.
 *
 * The plugin stores nothing about anybody. Its own table, local_cleanup_files, holds file pool
 * paths, MIME types, sizes and scan timestamps, and no user identifier appears in it.
 *
 * It does read and delete other components' data, which is the whole point of it, but reading
 * and deleting are not storing: the records belong to core and to the components that wrote
 * them, and those declare their own metadata. Deletions it performs on an administrator's
 * behalf are recorded by core's logstore through \local_cleanup\event\file_deleted, which is
 * core's store to describe rather than this plugin's.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements null_provider {
    /**
     * Explain why this plugin stores no personal data.
     *
     * @return string The identifier of a language string describing the reason
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
