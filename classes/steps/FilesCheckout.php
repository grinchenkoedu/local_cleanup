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

namespace local_cleanup\steps;

use file_storage;
use local_cleanup\output\OutputInterface;
use moodle_database;

/**
 * Files checkout cleanup step.
 *
 * Handles cleanup of backup and draft files based on configured timeouts.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class FilesCheckout implements CleanupStepInterface {

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
        file_storage    $fs,
        int $backuptimeoutdays = self::DEFAULT_TIMEOUT_DAYS,
        int $drafttimeoutdays = self::DEFAULT_TIMEOUT_DAYS
    ) {
        $this->db = $db;
        $this->fs = $fs;
        $this->backuptimeout = $backuptimeoutdays * 24 * 60 * 60;
        $this->drafttimeout = $drafttimeoutdays * 24 * 60 * 60;
    }

    /**
     * Execute the cleanup step.
     *
     * Checks all files and removes outdated backups and draft files.
     *
     * @param OutputInterface $output Output handler for logging
     * @return void
     */
    public function cleanup(OutputInterface $output) {
        $output->write('Fetching records... ');

        $ids = $this->db->get_fieldset_select('files', 'id', self::SELECT_ALL);
        $count = count($ids);
        $progress = 0;

        $output->writeLine(sprintf('%d records found.', count($ids)));
        $output->write('Processing... ');

        foreach ($ids as $index => $id) {
            $done = ceil(($index * 100) / $count);

            if ($done > $progress) {
                $output->write(sprintf('%d%%... ', $done));
                $progress = $done;
            }

            if ($this->checkout($id, $output)) {
                continue;
            }

            $this->db->delete_records('files', ['id' => $id]);
        }
    }

    /**
     * Check a file and remove it if it's outdated.
     *
     * @param int $id File ID to check
     * @param OutputInterface $output Output handler for logging
     * @return bool True if the file should be kept, false if it was removed
     */
    private function checkout($id, OutputInterface $output): bool {
        $file = $this->fs->get_file_by_id($id);

        if ($file === false) {
            // Wrong id provided (maybe already removed), continue...
            return true;
        }

        $resource = $this->fs->get_file_system()->get_content_file_handle($file);

        if ($resource === false) {
            $output->writeLine(sprintf('File "%s" is not found or not readable. Removed.', $id));

            return false;
        }

        $uri = stream_get_meta_data($resource)['uri'];
        fclose($resource);

        if (
            preg_match('/\.mbz$/', $file->get_filename())
            && $file->get_timecreated() <= time() - $this->backuptimeout
        ) {
            unlink($uri);
            $output->writeLine(sprintf(
                'Backup "%s" (%s) is outdated. Removed.',
                $file->get_filename(),
                $file->get_contenthash()
            ));

            return false;
        }

        if (
            $file->get_filearea() === 'draft'
            && $file->get_timecreated() <= time() - $this->drafttimeout
            && 1 === $this->db->count_records('files', ['contenthash' => $file->get_contenthash()])
        ) {
            unlink($uri);
            $output->writeLine(
                sprintf('Outdated draft "%s" (%s). Removed.',
                    $file->get_filename(),
                    $file->get_contenthash()
                ));

            return false;
        }

        return true;
    }
}
