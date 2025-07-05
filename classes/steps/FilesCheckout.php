<?php

namespace local_cleanup\steps;

use file_storage;
use local_cleanup\output\OutputInterface;
use moodle_database;

class FilesCheckout implements CleanupStepInterface
{
    const SELECT_ALL = '';
    const DEFAULT_TIMEOUT_DAYS = 30;

    private moodle_database $db;
    private file_storage $fs;
    private int $backupTimeout;
    private int $draftTimeout;

    public function __construct(
        moodle_database $db,
        file_storage    $fs,
        int $backupTimeoutDays = self::DEFAULT_TIMEOUT_DAYS,
        int $draftTimeoutDays = self::DEFAULT_TIMEOUT_DAYS
    ) {
        $this->db = $db;
        $this->fs = $fs;
        $this->backupTimeout = $backupTimeoutDays * 24 * 60 * 60;
        $this->draftTimeout = $draftTimeoutDays * 24 * 60 * 60;
    }

    public function cleanUp(OutputInterface $output)
    {
        $output->write('Fetching records... ');

        $ids = $this->db->get_fieldset_select('files', 'id', self::SELECT_ALL);
        $count = count($ids);
        $progress = 0;

        $output->writeLine(sprintf('%d records found.', count($ids)));
        $output->write('Processing... ');

        foreach ($ids as $index => $id) {
            $done = ceil(($index * 100) / $count);

            if ($done > $progress) {
                $output->write(sprintf('%d%%... ', $done));
                $progress = $done;
            }

            if ($this->checkout($id, $output)) {
                continue;
            }

            $this->db->delete_records('files', ['id' => $id]);
        }
    }

    private function checkout($id, OutputInterface $output): bool
    {
        $file = $this->fs->get_file_by_id($id);

        if ($file === false) {
            // wrong id provided (maybe already removed), continue...
            return true;
        }

        $resource = $this->fs->get_file_system()->get_content_file_handle($file);

        if ($resource === false) {
            $output->writeLine(sprintf('File "%s" is not found or not readable. Removed.', $id));

            return false;
        }

        $uri = stream_get_meta_data($resource)['uri'];
        fclose($resource);

        if (
            preg_match('/\.mbz$/', $file->get_filename())
            && $file->get_timecreated() <= time() - $this->backupTimeout
        ) {
            unlink($uri);
            $output->writeLine(sprintf(
                'Backup "%s" (%s) is outdated. Removed.',
                $file->get_filename(),
                $file->get_contenthash()
            ));

            return false;
        }

        if (
            $file->get_filearea() === 'draft'
            && $file->get_timecreated() <= time() - $this->draftTimeout
            && 1 === $this->db->count_records('files', ['contenthash' => $file->get_contenthash()])
        ) {
            unlink($uri);
            $output->writeLine(
                sprintf('Outdated draft "%s" (%s). Removed.',
                    $file->get_filename(),
                    $file->get_contenthash()
                ));

            return false;
        }

        return true;
    }
}
