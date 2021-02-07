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

    public function __construct()
    {
        global $DB, $CFG;

        $this->db = $DB;
        $this->dataRoot = $CFG->dataroot;
        $this->backupTimeout = $CFG->cleanup_backup_timeout ?? self::SECONDS_MONTH;
        $this->isAutoRemoveEnabled = (bool)$CFG->cleanup_run_autoremove ?? true;
        $this->fs = get_file_storage();
    }

    public function get_name()
    {
        return 'Database and disk clean-up';
    }

    public function execute()
    {
        $this->checkoutFiles();

        if ($this->isAutoRemoveEnabled) {
            $this->autoRemove();
        }
    }

    private function checkoutFiles()
    {
        mtrace('Fetching records... ', null);

        $ids = $this->db->get_fieldset_select('files', 'id', self::SELECT_ALL);

        mtrace(sprintf('%d records found.', count($ids)));

        foreach ($ids as $id) {
            if ($this->checkout($id)) {
                mtrace('Continue...');

                continue;
            }

            $this->db->delete_records('files', ['id' => $id]);

            mtrace('Removed!');
        }
    }

    private function autoRemove()
    {
        mtrace('Cleaning ghost files...', null);

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
        $file = $this->fs->get_file_by_id($id);

        if ($file === false) {
            // wrong id provided (maybe already removed), continue...
            return true;
        }

        $resource = $this->fs->get_file_system()->get_content_file_handle($file);

        if ($resource === false) {
            mtrace(sprintf('File "%s" is not found or not readable... ', $id), null);

            return false;
        }

        $uri = stream_get_meta_data($resource)['uri'];
        fclose($resource);

        if (
            preg_match('/\.mbz$/', $file->get_filename())
            && $file->get_timecreated() <= time() - $this->backupTimeout
        ) {
            mtrace(sprintf('Backup "%s" is outdated... ', $file->get_filename()), null);

            unlink($uri);

            return false;
        }

        mtrace(sprintf('File "%s" (id "%d") checked out! ', $uri, $id), null);

        return true;
    }
}
