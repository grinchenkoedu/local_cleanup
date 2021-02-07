<?php

namespace local_cleanup\task;

use core\task\scheduled_task;

class scan extends scheduled_task
{
    const FILE_DIR = 'filedir';

    /**
     * @var \moodle_database
     */
    private $db;

    /**
     * @var string
     */
    private $data_root;

    public function __construct()
    {
        global $DB, $CFG;

        $this->db = $DB;
        $this->data_root = $CFG->dataroot;
    }

    public function get_name()
    {
        return 'Scan for garbage';
    }

    public function execute()
    {
        $sizeTotal = $this->scanRecursive(self::FILE_DIR);

        mtrace(sprintf('Total found: %.3f GB', $sizeTotal / 1024 / 1024 / 1024));
    }

    private function scanRecursive(string $path): int
    {
        $size_total = 0;
        $absolute = $this->data_root . DIRECTORY_SEPARATOR . $path;
        $list = scandir($absolute);

        foreach ($list as $item) {
            if (preg_match('@^\.@', $item)) {
                continue;
            }

            $itemPath = $absolute . DIRECTORY_SEPARATOR . $item;

            if (is_dir($itemPath)) {
                mtrace(sprintf('Searching in "%s" ...', $itemPath));

                $size_total += $this->scanRecursive($path . DIRECTORY_SEPARATOR . $item);

                continue;
            }

            $record = $this->db->get_record('files', ['contenthash' => $item]);

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

                continue;
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
