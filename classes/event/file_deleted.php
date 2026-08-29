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

namespace local_cleanup\event;

use coding_exception;
use core\event\base;
use moodle_url;

/**
 * Raised when an administrator deletes a file from the files report.
 *
 * The plugin exists to destroy things, and until now it did so without leaving any record of
 * who removed what. The details live in "other" as well as in the record snapshot, because the
 * row is gone by the time anybody reads the log.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * The "other" array carries filename, filesize, component and contenthash. The content hash is
 * included because it may still back other records: deleting this one does not necessarily free
 * the bytes.
 */
class file_deleted extends base {
    /**
     * Describe the event to Moodle.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'files';
    }

    /**
     * Get the human-readable name of this event.
     *
     * @return string Name of the event
     */
    public static function get_name() {
        return get_string('event_file_deleted', 'local_cleanup');
    }

    /**
     * Describe what happened, for the log report.
     *
     * @return string Description of the event
     */
    public function get_description() {
        return "The user with id '$this->userid' deleted the file '{$this->other['filename']}' "
            . "with id '$this->objectid', owned by component '{$this->other['component']}', "
            . "freeing {$this->other['filesize']} bytes.";
    }

    /**
     * Where the log report should link to.
     *
     * @return moodle_url The files report
     */
    public function get_url() {
        return new moodle_url('/local/cleanup/files.php');
    }

    /**
     * The object this event refers to is a core file record, which is never restored.
     *
     * @return array Mapping definition
     */
    public static function get_objectid_mapping() {
        return ['db' => 'files', 'restore' => base::NOT_MAPPED];
    }

    /**
     * Nothing in "other" refers to something that could be mapped on restore.
     *
     * @return bool False, meaning there is nothing to map
     */
    public static function get_other_mapping() {
        return false;
    }

    /**
     * Check the event carries everything its description needs.
     *
     * @return void
     * @throws coding_exception When a required value is missing
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->objectid)) {
            throw new coding_exception('The \'objectid\' must be set.');
        }

        foreach (['filename', 'filesize', 'component', 'contenthash'] as $required) {
            if (!isset($this->other[$required])) {
                throw new coding_exception("The '$required' value must be set in other.");
            }
        }
    }
}
