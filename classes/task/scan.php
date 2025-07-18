<?php

namespace local_cleanup\task;

use core\task\scheduled_task;
use moodle_database;

class scan extends scheduled_task
{
    private moodle_database $db;
    private string $data_root;

    public function __construct()
    {
        global $DB, $CFG;

        $this->db = $DB;
        $this->data_root = $CFG->dataroot;
    }

    public function get_name()
    {
        return 'Scan for unlinked files';
    }

    public function execute()
    {
        $sizeTotal = $this->scanRecursive('filedir');

        mtrace(sprintf('Total found: %.3f GB', $sizeTotal / 1024 / 1024 / 1024));
    }

    private function scanRecursive(string $path, bool $printProgress = true): int
    {
        $size_total = 0;
        $absolute = $this->data_root . DIRECTORY_SEPARATOR . $path;
        $list = scandir($absolute);

        foreach ($list as $index => $item) {
            if (preg_match('@^\.@', $item)) {
                continue;
            }

            $itemPath = $absolute . DIRECTORY_SEPARATOR . $item;

            if (is_dir($itemPath)) {
                if ($printProgress) {
                    mtrace(sprintf(
                        'Searching in "%s" (%d%%)...',
                        $itemPath,
                        ($index * 100) / count($list)
                    ));
                }

                $size_total += $this->scanRecursive($path . DIRECTORY_SEPARATOR . $item, false);

                continue;
            }

            $record = $this->db->get_record('files', ['contenthash' => $item], 'id', IGNORE_MULTIPLE);

            if (empty($record)) {
                $size = filesize($itemPath);
                $size_total += $size;
                $mime = mime_content_type($itemPath);

                $this->insert($path . DIRECTORY_SEPARATOR . $item, $mime, $size);

                mtrace(
                    sprintf(
                        'Record NOT found for file "%s", added for removal.',
                        $itemPath
                    )
                );
            }
        }

        return $size_total;
    }

    private function insert($path, $mime, $size)
    {
        $existing = $this->db->get_record('cleanup', ['path' => $path]);

        if (!empty($existing)) {
            $existing->mime = $mime;
            $existing->size = $size;

            $this->db->update_record('cleanup', $existing);

            return;
        }

        $data = [
            'path' => $path,
            'mime' => $mime,
            'size' => $size
        ];

        $this->db->insert_record('cleanup', (object)$data);
    }
}
