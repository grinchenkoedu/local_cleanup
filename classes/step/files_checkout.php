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

namespace local_cleanup\step;

use file_storage;
use local_cleanup\output\output_interface;
use local_cleanup\step_result;
use moodle_database;
use stored_file;

/**
 * Files checkout cleanup step.
 *
 * Handles cleanup of backup and draft files based on configured timeouts.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class files_checkout implements step_interface {
    /**
     * Empty string for selecting all records.
     */
    const SELECT_ALL = '';

    /**
     * Default timeout in days for file removal.
     */
    const DEFAULT_TIMEOUT_DAYS = 30;

    /**
     * Database connection.
     *
     * @var moodle_database
     */
    private $db;

    /**
     * File storage instance.
     *
     * @var file_storage
     */
    private $fs;

    /**
     * Backup files timeout in seconds.
     *
     * @var int
     */
    private $backuptimeout;

    /**
     * Draft files timeout in seconds.
     *
     * @var int
     */
    private $drafttimeout;

    /**
     * Constructor.
     *
     * @param moodle_database $db Database connection
     * @param file_storage $fs File storage instance
     * @param int $backuptimeoutdays Number of days to keep backup files
     * @param int $drafttimeoutdays Number of days to keep draft files
     */
    public function __construct(
        moodle_database $db,
        file_storage $fs,
        int $backuptimeoutdays = self::DEFAULT_TIMEOUT_DAYS,
        int $drafttimeoutdays = self::DEFAULT_TIMEOUT_DAYS
    ) {
        $this->db = $db;
        $this->fs = $fs;
        $this->backuptimeout = $backuptimeoutdays * 24 * 60 * 60;
        $this->drafttimeout = $drafttimeoutdays * 24 * 60 * 60;
    }

    /**
     * Name this step.
     *
     * @return string Short human-readable name
     */
    public function get_name(): string {
        return 'Outdated backups and drafts';
    }

    /**
     * Count what would go, opening nothing for writing.
     *
     * @param output_interface $output Output handler for progress
     * @return step_result What would be removed
     */
    public function report(output_interface $output): step_result {
        return $this->walk($output, false);
    }

    /**
     * Remove the outdated backups and drafts.
     *
     * @param output_interface $output Output handler for progress
     * @return step_result What was removed
     */
    public function execute(output_interface $output): step_result {
        return $this->walk($output, true);
    }

    /**
     * Walk every file record, deciding on each and optionally acting.
     *
     * A recordset rather than get_fieldset_select(): this used to load the id of every file on
     * the site into memory at once, which on the sites this plugin exists for is millions of
     * integers.
     *
     * @param output_interface $output Output handler for progress
     * @param bool $delete Whether to actually remove what is found
     * @return step_result What was, or would be, removed
     */
    private function walk(output_interface $output, bool $delete): step_result {
        $result = new step_result();
        $output->write($delete ? 'Removing outdated files... ' : 'Checking for outdated files... ');

        $records = $this->db->get_recordset('files', [], '', 'id');

        foreach ($records as $record) {
            $file = $this->fs->get_file_by_id($record->id);

            if ($file === false) {
                // Already gone; nothing to decide.
                continue;
            }

            $reason = $this->removal_reason($file);

            if ($reason === null) {
                continue;
            }

            $result->add(1, (int)$file->get_filesize());

            if (!$delete) {
                continue;
            }

            $this->remove($file, $output);
            $output->write_line(sprintf(
                '%s "%s" (%s). Removed.',
                $reason,
                $file->get_filename(),
                $file->get_contenthash()
            ));
        }

        $records->close();
        $output->write_line($result->summarise());

        return $result;
    }

    /**
     * Decide whether a file is outdated, without touching it.
     *
     * @param stored_file $file File to judge
     * @return string|null Why it should go, or null to keep it
     */
    private function removal_reason(stored_file $file): ?string {
        $handle = $this->fs->get_file_system()->get_content_file_handle($file);

        if ($handle === false) {
            return 'Content missing for';
        }

        fclose($handle);

        if (!$this->is_last_reference($file)) {
            // Other records share these bytes, so removing them would break those records.
            return null;
        }

        if (
            preg_match('/\.mbz$/', $file->get_filename())
            && $file->get_timecreated() <= time() - $this->backuptimeout
        ) {
            return 'Outdated backup';
        }

        if (
            $file->get_filearea() === 'draft'
            && $file->get_timecreated() <= time() - $this->drafttimeout
        ) {
            return 'Outdated draft';
        }

        return null;
    }

    /**
     * Remove the pool file, where there is one, and then the record.
     *
     * @param stored_file $file File to remove
     * @param output_interface $output Output handler for progress
     * @return void
     */
    private function remove(stored_file $file, output_interface $output): void {
        $handle = $this->fs->get_file_system()->get_content_file_handle($file);

        if ($handle !== false) {
            $uri = stream_get_meta_data($handle)['uri'];
            fclose($handle);

            if (!unlink($uri)) {
                $output->write_line(sprintf('Could not unlink "%s".', $uri));
            }
        }

        $this->db->delete_records('files', ['id' => $file->get_id()]);
    }

    /**
     * Check whether this record is the only one referencing its content.
     *
     * Moodle deduplicates file content by hash, so the pool file may only be unlinked when no
     * other record points at it. Without this the removal of one outdated backup destroys the
     * content of every other record sharing the same bytes.
     *
     * @param stored_file $file File to check
     * @return bool True when no other record shares the content hash
     */
    private function is_last_reference(stored_file $file): bool {
        return 1 === $this->db->count_records('files', ['contenthash' => $file->get_contenthash()]);
    }
}
