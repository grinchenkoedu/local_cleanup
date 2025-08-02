<?php

namespace local_cleanup\steps;

use local_cleanup\output\OutputInterface;
use moodle_database;

class ComponentFilesCleanup extends AbstractCleanupStep
{
    const DEFAULT_LIFETIME_DAYS = 180;

    private array $components;
    private int $daysToKeep;

    public function __construct(moodle_database $db, array $components, int $daysToKeep = self::DEFAULT_LIFETIME_DAYS)
    {
        parent::__construct($db);

        $this->components = $components;
        $this->daysToKeep = $daysToKeep;
    }

    public function cleanUp(OutputInterface $output)
    {
        $output->writeLine('Starting component files cleanup...');

        $cutoffDate = time() - ($this->daysToKeep * 24 * 60 * 60);

        foreach ($this->components as $component) {
            $output->writeLine("Processing component '$component'...");
            
            $params = [
                'component' => $component,
                'cutoffdate' => $cutoffDate
            ];

            $sql = "SELECT f.id
                    FROM {files} f
                    WHERE f.component = :component
                    AND f.timecreated < :cutoffdate";

            $this->processRecordsInBatches(
                'files',
                'f',
                $sql,
                $params,
                "Checking for files to clean up in component '$component'...",
                $output
            );
        }

        $output->writeLine('Component files cleanup completed.');
    }
}
