<?php

namespace local_cleanup\steps;

use local_cleanup\output\OutputInterface;
use moodle_database;

abstract class AbstractCleanupStep implements CleanupStepInterface
{
    protected moodle_database $db;
    const BATCH_SIZE = 999;

    public function __construct(moodle_database $db)
    {
        $this->db = $db;
    }

    abstract public function cleanUp(OutputInterface $output);

    /**
     * Process records in batches and delete them
     *
     * @param string $table The database table name
     * @param string $sql The SQL query to find records to delete
     * @param array $params The parameters for the SQL query
     * @param string $message The message to display when checking for records
     * @param OutputInterface $output The output interface for logging
     */
    protected function processRecordsInBatches(
        $table,
        $alias,
        $sql,
        $params,
        $message,
        OutputInterface $output
    ): void {
        $output->writeLine(sprintf('Cleaning %s: %s', $table, $message));

        $limit = self::BATCH_SIZE * 100;
        $totalDeleted = 0;
        $batchNumber = 0;
        $lastId = 0;

        do {
            if ($batchNumber > 0) {
                $output->writeLine(
                    sprintf(
                        'Cleaning %s: Loading batch %d...',
                        $table,
                        $batchNumber + 1
                    )
                );
            }

            $boundedSql = sprintf(
                '%s AND %s.id > :lastid ORDER BY %s.id ASC LIMIT %d',
                $sql,
                $alias,
                $alias,
                $limit
            );
            $boundedParams = array_merge($params, ['lastid' => $lastId]);

            $startTime = microtime(true);

            $ids = $this->db->get_fieldset_sql($boundedSql, $boundedParams);
            $lastId = end($ids);
            $count = count($ids);
            $batchNumber++;

            if ($count > 0) {
                $output->write('Deleting..');

                while (!empty($ids)) {
                    $batchIds = array_splice($ids, 0, self::BATCH_SIZE);
                    $batchCount = count($batchIds);
                    $totalDeleted += $batchCount;

                    $this->db->delete_records_list($table, 'id', $batchIds);
                    $output->write('.');
                }

                $endTime = microtime(true);
                $elapsedSeconds = $endTime - $startTime;
                $output->writeLine(
                    sprintf(
                        'OK (took %02d:%02d)',
                        floor($elapsedSeconds / 60),
                        floor($elapsedSeconds % 60)
                    )
                );
            }
        } while ($count === $limit);

        if ($totalDeleted === 0) {
            $output->writeLine('None found.');

            return;
        }

        $output->writeLine("Total records deleted: $totalDeleted. Done.");
    }
}
