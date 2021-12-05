<?php

namespace local_cleanup\task;

use core\task\scheduled_task;
use moodle_database;
use file_storage;

class cleanup extends scheduled_task
{
    const SELECT_ALL = '';
    const SECONDS_MONTH = 2592000;

    /**
     * @var moodle_database
     */
    private $db;

    /**
     * @var file_storage
     */
    private $fs;

    /**
     * @var string
     */
    private $dataRoot;

    /**
     * @var bool
     */
    private $isAutoRemoveEnabled;

    /**
     * @var int
     */
    private $backupTimeout;

    /**
     * @var int
     */
    private $draftTimeout;

    public function __construct()
    {
        global $DB, $CFG;

        $this->db = $DB;
        $this->dataRoot = $CFG->dataroot;
        $this->backupTimeout = $CFG->cleanup_backup_timeout ?? self::SECONDS_MONTH;
        $this->draftTimeout = $CFG->cleanup_draft_timeout ?? self::SECONDS_MONTH;
        $this->isAutoRemoveEnabled = (bool)$CFG->cleanup_run_autoremove ?? true;
        $this->fs = get_file_storage();
    }

    public function get_name()
    {
        return 'Database and disk clean-up';
    }

    public function execute()
    {
        if ($this->isAutoRemoveEnabled) {
            $this->autoRemove();
        }

        $this->checkoutFiles();
    }

    private function clearDirectory($name, $printTrace = true)
    {
        $directory = $this->dataRoot . DIRECTORY_SEPARATOR . $name;

        if ($printTrace) {
            mtrace(sprintf('Clearing %s... ', $directory), null);
        }

        $files = scandir($directory);

        foreach ($files as $file) {
            if (in_array($file, ['..', '.'], true)) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $file;

            if (is_dir($path)) {
                $this->clearDirectory($name . DIRECTORY_SEPARATOR . $file, false);

                continue;
            }

            unlink($path);
        }

        if ($printTrace) {
            mtrace('Done!');
        }
    }

    private function checkoutFiles()
    {
        mtrace('Fetching records... ', null);

        $ids = $this->db->get_fieldset_select('files', 'id', self::SELECT_ALL);
        $count = count($ids);
        $progress = 0;

        mtrace(sprintf('%d records found.', count($ids)));
        mtrace('Processing... ', null);

        foreach ($ids as $index => $id) {
            $done = ceil(($index * 100) / $count);

            if ($done > $progress) {
                mtrace(sprintf('%d%%... ', $done), null);
                $progress = $done;
            }

            if ($this->checkout($id)) {
                continue;
            }

            $this->db->delete_records('files', ['id' => $id]);
        }
    }

    private function autoRemove()
    {
        $this->clearDirectory('trashdir');
        $this->clearDirectory('temp');

        mtrace('Clearing ghost files... ', null);

        $ghost_files = $this->db->get_recordset('cleanup', [], '', 'id, path');

        foreach ($ghost_files as $item) {
            $path = $this->dataRoot . DIRECTORY_SEPARATOR . $item->path;

            if (file_exists($path)) {
                unlink($path);

                $this->db->delete_records('cleanup', ['id' => $item->id]);
            }

            mtrace('.', null);
        }

        mtrace('Done!');
    }

    /**
     * @param int|string $id
     *
     * @return bool
     */
    private function checkout($id)
    {
        global $DB;

        $file = $this->fs->get_file_by_id($id);

        if ($file === false) {
            // wrong id provided (maybe already removed), continue...
            return true;
        }

        $resource = $this->fs->get_file_system()->get_content_file_handle($file);

        if ($resource === false) {
            mtrace(sprintf('File "%s" is not found or not readable. Removed.', $id));

            return false;
        }

        $uri = stream_get_meta_data($resource)['uri'];
        fclose($resource);

        if (
            preg_match('/\.mbz$/', $file->get_filename())
            && $file->get_timecreated() <= time() - $this->backupTimeout
        ) {
            unlink($uri);
            mtrace(sprintf(
                'Backup "%s" (%s) is outdated. Removed.',
                $file->get_filename(),
                $file->get_contenthash()
            ));

            return false;
        }

        if (
            $file->get_filearea() === 'draft'
            && $file->get_timecreated() <= time() - $this->draftTimeout
            && 1 === $DB->count_records('files', ['contenthash' => $file->get_contenthash()])
        ) {
            unlink($uri);
            mtrace(
                sprintf('Outdated draft "%s" (%s). Removed.',
                    $file->get_filename(),
                    $file->get_contenthash()
                ));

            return false;
        }

        return true;
    }
}
