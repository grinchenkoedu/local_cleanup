<?php

namespace local_cleanup\steps;

use local_cleanup\output\OutputInterface;
use moodle_database;

class LogsCleanup extends AbstractCleanupStep
{
    const DEFAULT_LIFETIME_DAYS = 500;

    private int $cutoffDate;
    private int $cutoffDays;

    public function __construct(moodle_database $db, int $daysToKeep = self::DEFAULT_LIFETIME_DAYS)
    {
        parent::__construct($db);

        $this->cutoffDate = time() - $daysToKeep * 24 * 60 * 60;
        $this->cutoffDays = $daysToKeep;
    }

    public function cleanUp(OutputInterface $output)
    {
        $output->writeLine('Starting logs cleanup...');

        $this->cleanupStandardLogs($output);
        $this->cleanupLAnalyticsLogs($output);

        $output->writeLine('Logs cleanup completed.');
    }

    private function cleanupStandardLogs(OutputInterface $output)
    {
        $sql = "SELECT l.id
                FROM {logstore_standard_log} l
                LEFT JOIN {context} ctx ON ctx.id = l.contextid
                WHERE ctx.id IS NULL
                      OR l.timecreated < :cutoffdate";

        $this->processRecordsInBatches(
            'logstore_standard_log',
            'l',
            $sql,
            ['cutoffdate' => $this->cutoffDate],
            sprintf(
                'Checking for logs to clean up (obsolete or older than %d days)...',
                $this->cutoffDays
            ),
            $output
        );
    }

    private function cleanupLAnalyticsLogs(OutputInterface $output)
    {
        if (!$this->db->get_manager()->table_exists('logstore_lanalytics_log')) {
            $output->writeLine('Skipping LAnalytics logs cleanup: table logstore_lanalytics_log does not exist.');
            return;
        }

        $sql = "SELECT l.id
                FROM {logstore_lanalytics_log} l
                LEFT JOIN {context} ctx ON ctx.id = l.contextid
                WHERE ctx.id IS NULL
                      OR l.timecreated < :cutoffdate";

        $this->processRecordsInBatches(
            'logstore_lanalytics_log',
            'l',
            $sql,
            ['cutoffdate' => $this->cutoffDate],
            sprintf(
                'Checking for logs to clean up (obsolete or older than %d days)...',
                $this->cutoffDays
            ),
            $output
        );
    }
}
