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
use local_cleanup\config;
use file_storage;
use local_cleanup\output\MtraceOutput;
use local_cleanup\steps\CleanupStepInterface;
use local_cleanup\steps\ComponentFilesCleanup;
use local_cleanup\steps\CourseModulesCleanup;
use local_cleanup\steps\FilesCheckout;
use local_cleanup\steps\GhostFilesCleanup;
use local_cleanup\steps\GradesCleanup;
use local_cleanup\steps\LogsCleanup;
use moodle_database;

/**
 * Scheduled task for database and disk cleanup.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends scheduled_task {
    /**
     * Array of cleanup steps to execute.
     *
     * @var CleanupStepInterface[]
     */
    private $steps = [];

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
     * Moodle data root directory path.
     *
     * @var string
     */
    private $dataroot;

    /**
     * Whether automatic removal is enabled.
     *
     * @var bool
     */
    private $isautoremoveenabled;

    /**
     * Number of days to keep backup files.
     *
     * @var int
     */
    private $backuptimeout;

    /**
     * Number of days to keep draft files.
     *
     * @var int
     */
    private $drafttimeout;

    /**
     * Number of days to keep logs.
     *
     * @var int
     */
    private $logstimeout;

    /**
     * Number of days to keep component files.
     *
     * @var int
     */
    private $componentfilesdays;

    /**
     * Components the administrator opted in to cleaning up.
     *
     * @var string[]
     */
    private $componentfiles = [];

    /**
     * Number of days to keep grades.
     *
     * @var int
     */
    private $gradesdays;

    /**
     * Number of days to keep course modules.
     *
     * @var int
     */
    private $coursemodulesdays;

    /**
     * Constructor.
     *
     * Initializes the task with configuration from Moodle settings.
     */
    public function __construct() {
        global $DB, $CFG;

        $this->db = $DB;
        $this->dataroot = $CFG->dataroot;
        $this->backuptimeout = config::backup_lifetime_days();
        $this->drafttimeout = config::draft_lifetime_days();
        $this->logstimeout = config::logs_lifetime_days();
        $this->componentfilesdays = config::component_files_lifetime_days();
        $this->componentfiles = config::component_files();
        $this->gradesdays = config::grades_lifetime_days();
        $this->coursemodulesdays = config::course_modules_lifetime_days();
        $this->isautoremoveenabled = config::autoremove_enabled();
        $this->fs = get_file_storage();

        $this->initializeSteps();
    }

    /**
     * Get the name of the task.
     *
     * @return string The name of the task
     */
    public function get_name() {
        return 'Database and disk clean-up';
    }

    /**
     * Execute the task.
     *
     * Runs all configured cleanup steps.
     */
    public function execute() {
        $output = new MtraceOutput();

        foreach ($this->steps as $step) {
            $step->cleanUp($output);
        }
    }

    /**
     * Initialize the cleanup steps based on configuration.
     */
    private function initializesteps() {
        if ($this->isautoremoveenabled) {
            $this->steps[] = new CourseModulesCleanup($this->db, $this->coursemodulesdays);
            $this->steps[] = new GradesCleanup($this->db, $this->gradesdays);
            $this->steps[] = new LogsCleanup($this->db, $this->logstimeout);
            // Nothing is cleaned up per component until an administrator names one, so an
            // empty list means this step has no work rather than a default set of victims.
            if (!empty($this->componentfiles)) {
                $this->steps[] = new ComponentFilesCleanup(
                    $this->db,
                    $this->componentfiles,
                    $this->componentfilesdays
                );
            }
            $this->steps[] = new GhostFilesCleanup($this->db, $this->dataroot);
            $this->steps[] = new FilesCheckout($this->db, $this->fs, $this->backuptimeout, $this->drafttimeout);
        }
    }
}
