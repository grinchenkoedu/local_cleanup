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

use local_cleanup\output\output_interface;
use local_cleanup\step_result;
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
class ghost_files_cleanup implements step_interface {
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
     * Default days between the two scans that must agree before a file is removed.
     */
    const DEFAULT_GRACE_DAYS = 7;

    /**
     * Seconds that must separate the first and latest sighting.
     *
     * @var int
     */
    private $grace;

    /**
     * Constructor.
     *
     * @param moodle_database $db Database connection
     * @param string $dataroot Path to Moodle data directory
     * @param int $gracedays Days between the two scans that must agree
     */
    public function __construct(moodle_database $db, string $dataroot, int $gracedays = self::DEFAULT_GRACE_DAYS) {
        $this->db = $db;
        $this->dataroot = $dataroot;
        $this->grace = $gracedays * DAYSECS;
    }

    /**
     * Name this step.
     *
     * @return string Short human-readable name
     */
    public function get_name(): string {
        return 'Unlinked files';
    }

    /**
     * Count the recorded files that are still unreferenced, deleting none of them.
     *
     * @param output_interface $output Output handler for progress
     * @return step_result What would be removed
     */
    public function report(output_interface $output): step_result {
        return $this->walk($output, false);
    }

    /**
     * Remove the recorded files that are still unreferenced.
     *
     * @param output_interface $output Output handler for progress
     * @return step_result What was removed
     */
    public function execute(output_interface $output): step_result {
        return $this->walk($output, true);
    }

    /**
     * Walk the scan results, re-checking each and optionally acting.
     *
     * @param output_interface $output Output handler for progress
     * @param bool $delete Whether to actually remove what is found
     * @return step_result What was, or would be, removed
     */
    private function walk(output_interface $output, bool $delete): step_result {
        $result = new step_result();
        $output->write($delete ? 'Deleting unlinked files... ' : 'Checking unlinked files... ');

        $ghostfiles = $this->db->get_recordset(
            'local_cleanup_files',
            [],
            '',
            'id, path, size, timeconfirmed, timescanned'
        );
        $reclaimed = 0;
        $waiting = 0;

        foreach ($ghostfiles as $item) {
            // The scan that recorded this file runs on its own schedule, so this list can be
            // days old. A file uploaded since then deduplicates onto an existing content hash,
            // which would make it indistinguishable from a ghost. Re-check before acting.
            if ($this->db->record_exists('files', ['contenthash' => basename($item->path)])) {
                $reclaimed++;

                if ($delete) {
                    $this->db->delete_records('local_cleanup_files', ['id' => $item->id]);
                }

                continue;
            }

            // One sighting is not enough. Two scans a grace period apart have to agree, so
            // that content appearing between them is never destroyed on a single observation.
            if ((int)$item->timescanned - (int)$item->timeconfirmed < $this->grace) {
                $waiting++;

                continue;
            }

            $result->add(1, (int)$item->size);

            if (!$delete) {
                continue;
            }

            if ($this->move_to_trash($item->path)) {
                $output->write('.');
            } else {
                $output->write('E');
            }

            $this->db->delete_records('local_cleanup_files', ['id' => $item->id]);
        }

        $ghostfiles->close();

        if ($reclaimed > 0) {
            $this->note($result, $output, sprintf(
                '%d file(s) referenced again since the scan were kept.',
                $reclaimed
            ));
        }

        if ($waiting > 0) {
            $this->note($result, $output, sprintf(
                '%d file(s) have not yet been seen unlinked by two scans a grace period apart.',
                $waiting
            ));
        }

        $output->write_line($result->summarise());

        return $result;
    }

    /**
     * Record a note on the result and show it as it happens.
     *
     * @param step_result $result Result to note against
     * @param output_interface $output Output handler
     * @param string $note The note
     * @return void
     */
    private function note(step_result $result, output_interface $output, string $note): void {
        $result->note($note);
        $output->write_line($note);
    }

    /**
     * Move a pool file into the trash directory rather than unlinking it.
     *
     * Moodle's own trash clean-up task empties that directory later, which turns an
     * irreversible delete into one an administrator has a window to undo.
     *
     * @param string $relativepath Path of the file relative to dataroot
     * @return bool True when the file was moved, or was already gone
     */
    private function move_to_trash(string $relativepath): bool {
        $source = $this->dataroot . DIRECTORY_SEPARATOR . $relativepath;

        if (!file_exists($source)) {
            return true;
        }

        $hash = basename($relativepath);
        $target = $this->dataroot . DIRECTORY_SEPARATOR . 'trashdir'
            . DIRECTORY_SEPARATOR . substr($hash, 0, 2)
            . DIRECTORY_SEPARATOR . substr($hash, 2, 2)
            . DIRECTORY_SEPARATOR . $hash;

        if (file_exists($target)) {
            // Already waiting in the trash; the pool copy is the redundant one.
            return unlink($source);
        }

        check_dir_exists(dirname($target));

        // A rename is atomic only within one filesystem. Both directories live under
        // dataroot, so this holds today; it would not if filedir were ever mounted separately.
        return rename($source, $target);
    }
}
