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
        $this->backuptimeout = $CFG->cleanup_backup_timeout_days ?? FilesCheckout::DEFAULT_TIMEOUT_DAYS;
        $this->drafttimeout = $CFG->cleanup_draft_timeout ?? FilesCheckout::DEFAULT_TIMEOUT_DAYS;
        $this->logstimeout = $CFG->cleanup_logs_timeout_days ?? LogsCleanup::DEFAULT_LIFETIME_DAYS;
        $this->componentfilesdays = $CFG->cleanup_component_files_days ?? ComponentFilesCleanup::DEFAULT_LIFETIME_DAYS;
        $this->gradesdays = $CFG->cleanup_grades_days ?? GradesCleanup::DEFAULT_LIFETIME_DAYS;
        $this->coursemodulesdays = $CFG->cleanup_course_modules_days ?? CourseModulesCleanup::DEFAULT_LIFETIME_DAYS;
        $this->isautoremoveenabled = (bool)$CFG->cleanup_run_autoremove ?? false;
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
            $this->steps[] = new ComponentFilesCleanup($this->db, [
                'assignsubmission_file',
                'backup',
            ], $this->componentfilesdays);
            $this->steps[] = new GhostFilesCleanup($this->db, $this->dataroot);
        }

        $this->steps[] = new FilesCheckout($this->db, $this->fs, $this->backuptimeout, $this->drafttimeout);
    }
}
