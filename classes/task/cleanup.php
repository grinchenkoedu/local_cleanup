<?php

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

class cleanup extends scheduled_task
{
    /**
     * @var CleanupStepInterface[]
     */
    private array $steps = [];
    private moodle_database $db;
    private file_storage $fs;
    private string $dataRoot;
    private bool $isAutoRemoveEnabled;
    private int $backupTimeout;
    private int $draftTimeout;
    private int $logsTimeout;
    private int $componentFilesDays;
    private int $gradesDays;
    private int $courseModulesDays;

    public function __construct()
    {
        global $DB, $CFG;

        $this->db = $DB;
        $this->dataRoot = $CFG->dataroot;
        $this->backupTimeout = $CFG->cleanup_backup_timeout_days ?? FilesCheckout::DEFAULT_TIMEOUT_DAYS;
        $this->draftTimeout = $CFG->cleanup_draft_timeout ?? FilesCheckout::DEFAULT_TIMEOUT_DAYS;
        $this->logsTimeout = $CFG->cleanup_logs_timeout_days ?? LogsCleanup::DEFAULT_LIFETIME_DAYS;
        $this->componentFilesDays = $CFG->cleanup_component_files_days ?? ComponentFilesCleanup::DEFAULT_LIFETIME_DAYS;
        $this->gradesDays = $CFG->cleanup_grades_days ?? GradesCleanup::DEFAULT_LIFETIME_DAYS;
        $this->courseModulesDays = $CFG->cleanup_course_modules_days ?? CourseModulesCleanup::DEFAULT_LIFETIME_DAYS;
        $this->isAutoRemoveEnabled = (bool)$CFG->cleanup_run_autoremove ?? false;
        $this->fs = get_file_storage();

        $this->initializeSteps();
    }

    public function get_name()
    {
        return 'Database and disk clean-up';
    }

    public function execute()
    {
        $output = new MtraceOutput();

        foreach ($this->steps as $step) {
            $step->cleanUp($output);
        }
    }

    private function initializeSteps()
    {
        if ($this->isAutoRemoveEnabled) {
            $this->steps[] = new CourseModulesCleanup($this->db, $this->courseModulesDays);
            $this->steps[] = new GradesCleanup($this->db, $this->gradesDays);
            $this->steps[] = new LogsCleanup($this->db, $this->logsTimeout);
            $this->steps[] = new ComponentFilesCleanup($this->db, [
                'assignsubmission_file',
                'backup',
            ], $this->componentFilesDays);
            $this->steps[] = new GhostFilesCleanup($this->db, $this->dataRoot);
        }

        $this->steps[] = new FilesCheckout($this->db, $this->fs, $this->backupTimeout, $this->draftTimeout);
    }
}
