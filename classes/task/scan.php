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

namespace local_cleanup\task;

use core\task\scheduled_task;
use moodle_database;

/**
 * Scheduled task for scanning unlinked files.
 *
 * Scans the file system for files that are not referenced in the database.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scan extends scheduled_task {
    /**
     * Database connection.
     *
     * @var moodle_database
     */
    private $db;

    /**
     * Moodle data root directory path.
     *
     * @var string
     */
    private $dataroot;

    /**
     * Timestamp for this run, so every row the scan touches agrees.
     *
     * @var int
     */
    private $now = 0;

    /**
     * Constructor.
     */
    public function __construct() {
        global $DB, $CFG;

        $this->db = $DB;
        $this->dataroot = $CFG->dataroot;
    }

    /**
     * Get the name of the task.
     *
     * @return string The name of the task
     */
    public function get_name() {
        return 'Scan for unlinked files';
    }

    /**
     * How many content hashes to look up in one query.
     */
    const LOOKUP_CHUNK = 500;

    /**
     * Execute the task.
     *
     * Walks the file pool and records anything the {files} table does not reference.
     *
     * @return void
     */
    public function execute() {
        global $CFG;

        if (!empty($CFG->alternative_file_system_class)) {
            mtrace(
                'This site stores files through ' . $CFG->alternative_file_system_class
                . ', so there is no local file pool to walk. Nothing to do.'
            );

            return;
        }

        $this->now = time();
        $sizetotal = $this->scan_recursive('filedir');

        mtrace(sprintf('Total found: %s', display_size($sizetotal)));
    }

    /**
     * Recursively scan a directory for unlinked files.
     *
     * @param string $path Relative path to scan
     * @param bool $printprogress Whether to print progress information
     * @return int Total size of unlinked files found in bytes
     */
    private function scan_recursive(string $path, bool $printprogress = true): int {
        $sizetotal = 0;
        $absolute = $this->dataroot . DIRECTORY_SEPARATOR . $path;
        $list = scandir($absolute);
        $files = [];

        foreach ($list as $index => $item) {
            if (preg_match('@^\.@', $item)) {
                continue;
            }

            $itempath = $absolute . DIRECTORY_SEPARATOR . $item;

            if (is_dir($itempath)) {
                if ($printprogress) {
                    mtrace(sprintf(
                        'Searching in "%s" (%d%%)...',
                        $itempath,
                        ($index * 100) / count($list)
                    ));
                }

                $sizetotal += $this->scan_recursive($path . DIRECTORY_SEPARATOR . $item, false);

                continue;
            }

            $files[$item] = $itempath;
        }

        return $sizetotal + $this->record_unreferenced($path, $files);
    }

    /**
     * Record whichever of these files the {files} table does not reference.
     *
     * The hashes are looked up in chunks rather than one query per file. A pool directory holds
     * a few hundred files and a large site has many thousands of directories, so the per-file
     * query was the bulk of the scan's cost.
     *
     * @param string $path Directory path relative to dataroot
     * @param array $files Map of content hash to absolute path
     * @return int Total size of the unreferenced files found here
     */
    private function record_unreferenced(string $path, array $files): int {
        $sizetotal = 0;

        foreach (array_chunk(array_keys($files), self::LOOKUP_CHUNK) as $hashes) {
            [$insql, $params] = $this->db->get_in_or_equal($hashes, SQL_PARAMS_NAMED);
            $referenced = $this->db->get_fieldset_select(
                'files',
                'DISTINCT contenthash',
                "contenthash $insql",
                $params
            );

            foreach (array_diff($hashes, $referenced) as $hash) {
                $itempath = $files[$hash];
                $size = (int)filesize($itempath);
                $sizetotal += $size;

                $this->record($path . DIRECTORY_SEPARATOR . $hash, mime_content_type($itempath), $size);
            }
        }

        return $sizetotal;
    }

    /**
     * Note that this file is still unreferenced.
     *
     * timeconfirmed is the first sighting and never moves; timescanned is the latest. The
     * clean-up step needs both, because it only removes a file that two scans a grace period
     * apart have each found unreferenced.
     *
     * @param string $path File path relative to dataroot
     * @param string $mime MIME type of the file
     * @param int $size Size of the file in bytes
     * @return void
     */
    private function record(string $path, string $mime, int $size): void {
        $existing = $this->db->get_record('local_cleanup_files', ['path' => $path]);

        if (!empty($existing)) {
            $existing->mime = $mime;
            $existing->size = $size;
            $existing->timescanned = $this->now;

            if (empty($existing->timeconfirmed)) {
                // Recorded before this bookkeeping existed; treat now as the first sighting.
                $existing->timeconfirmed = $this->now;
            }

            $this->db->update_record('local_cleanup_files', $existing);

            return;
        }

        $this->db->insert_record('local_cleanup_files', (object)[
            'path' => $path,
            'mime' => $mime,
            'size' => $size,
            'timeconfirmed' => $this->now,
            'timescanned' => $this->now,
        ]);

        mtrace(sprintf('No record for "%s"; noted as unlinked.', $path));
    }
}
