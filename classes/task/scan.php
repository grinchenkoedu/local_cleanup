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
     * Execute the task.
     *
     * Scans for unlinked files and reports the total size found.
     */
    public function execute() {
        $sizetotal = $this->scanRecursive('filedir');

        mtrace(sprintf('Total found: %.3f GB', $sizetotal / 1024 / 1024 / 1024));
    }

    /**
     * Recursively scan a directory for unlinked files.
     *
     * @param string $path Relative path to scan
     * @param bool $printprogress Whether to print progress information
     * @return int Total size of unlinked files found in bytes
     */
    private function scanrecursive(string $path, bool $printprogress = true): int {
        $sizetotal = 0;
        $absolute = $this->dataroot . DIRECTORY_SEPARATOR . $path;
        $list = scandir($absolute);

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

                $sizetotal += $this->scanRecursive($path . DIRECTORY_SEPARATOR . $item, false);

                continue;
            }

            $record = $this->db->get_record('files', ['contenthash' => $item], 'id', IGNORE_MULTIPLE);

            if (empty($record)) {
                $size = filesize($itempath);
                $sizetotal += $size;
                $mime = mime_content_type($itempath);

                $this->insert($path . DIRECTORY_SEPARATOR . $item, $mime, $size);

                mtrace(
                    sprintf(
                        'Record NOT found for file "%s", added for removal.',
                        $itempath
                    )
                );
            }
        }

        return $sizetotal;
    }

    /**
     * Insert or update a record in the local_cleanup_files table.
     *
     * @param string $path File path relative to dataroot
     * @param string $mime MIME type of the file
     * @param int $size Size of the file in bytes
     */
    private function insert($path, $mime, $size) {
        $existing = $this->db->get_record('local_cleanup_files', ['path' => $path]);

        if (!empty($existing)) {
            $existing->mime = $mime;
            $existing->size = $size;

            $this->db->update_record('local_cleanup_files', $existing);

            return;
        }

        $data = [
            'path' => $path,
            'mime' => $mime,
            'size' => $size,
        ];

        $this->db->insert_record('local_cleanup_files', (object)$data);
    }
}
