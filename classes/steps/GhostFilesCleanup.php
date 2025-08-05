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

use local_cleanup\output\OutputInterface;
use moodle_database;

/**
 * Ghost files cleanup step.
 *
 * Removes files that are tracked in the cleanup table but no longer referenced in the files table.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class GhostFilesCleanup implements CleanupStepInterface {

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
     *
     * @param moodle_database $db Database connection
     * @param string $dataroot Path to Moodle data directory
     */
    public function __construct(moodle_database $db, string $dataroot) {
        $this->db = $db;
        $this->dataroot = $dataroot;
    }

    /**
     * Execute the cleanup step.
     *
     * Removes ghost files that are tracked in the cleanup table.
     *
     * @param OutputInterface $output Output handler for logging
     * @return void
     */
    public function cleanup(OutputInterface $output) {
        $output->write('Deleting unlinked files... ');

        $ghostfiles = $this->db->get_recordset('local_cleanup_files', [], '', 'id, path');

        foreach ($ghostfiles as $item) {
            $path = $this->dataroot . DIRECTORY_SEPARATOR . $item->path;

            if (file_exists($path) && unlink($path)) {
                $output->write('.');
            } else {
                $output->write('E');
            }

            $this->db->delete_records('local_cleanup_files', ['id' => $item->id]);
        }

        $output->writeLine('Done!');
    }
}
