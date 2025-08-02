<?php

namespace local_cleanup\steps;

use local_cleanup\output\OutputInterface;
use moodle_database;

class GhostFilesCleanup implements CleanupStepInterface
{
    private moodle_database $db;
    private string $dataRoot;

    public function __construct(moodle_database $db, string $dataRoot)
    {
        $this->db = $db;
        $this->dataRoot = $dataRoot;
    }

    public function cleanUp(OutputInterface $output)
    {
        $output->write('Deleting unlinked files... ');

        $ghost_files = $this->db->get_recordset('cleanup', [], '', 'id, path');

        foreach ($ghost_files as $item) {
            $path = $this->dataRoot . DIRECTORY_SEPARATOR . $item->path;

            if (file_exists($path) && unlink($path)) {
                $output->write('.');
            } else {
                $output->write('E');
            }

            $this->db->delete_records('cleanup', ['id' => $item->id]);
        }

        $output->writeLine('Done!');
    }
}
