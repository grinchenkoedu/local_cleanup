<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_cleanup\step;

use file_storage;
use local_cleanup\output\output_interface;
use local_cleanup\config;
use local_cleanup\step_result;
use moodle_database;
use stored_file;

/**
 * Files checkout cleanup step.
 *
 * Handles cleanup of backup and draft files based on configured timeouts.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class files_checkout implements step_interface {
    /**
     * Empty string for selecting all records.
     */
    const SELECT_ALL = '';

    /**
     * Default timeout in days for file removal.
     */
    const DEFAULT_TIMEOUT_DAYS = 30;

    /**
     * Database connection.
     *
     * @var moodle_database
     */
    private $db;

    /**
     * File storage instance.
     *
     * @var file_storage
     */
    private $fs;

    /**
     * Backup files timeout in seconds.
     *
     * @var int
     */
    private $backuptimeout;

    /**
     * Draft files timeout in seconds.
     *
     * @var int
     */
    private $drafttimeout;

    /**
     * Constructor.
     *
     * @param moodle_database $db Database connection
     * @param file_storage $fs File storage instance
     * @param int $backuptimeoutdays Number of days to keep backup files
     * @param int $drafttimeoutdays Number of days to keep draft files
     */
    public function __construct(
        moodle_database $db,
        file_storage $fs,
        int $backuptimeoutdays = self::DEFAULT_TIMEOUT_DAYS,
        int $drafttimeoutdays = self::DEFAULT_TIMEOUT_DAYS
    ) {
        $this->db = $db;
        $this->fs = $fs;
        $this->backuptimeout = $backuptimeoutdays * 24 * 60 * 60;
        $this->drafttimeout = $drafttimeoutdays * 24 * 60 * 60;
    }

    /**
     * Candidates fetched per batch.
     */
    const BATCH_SIZE = 500;

    /**
     * Name this step.
     *
     * @return string Short human-readable name
     */
    public function get_name(): string {
        return 'Outdated backups and drafts';
    }

    /**
     * Count what would go. One query, touching no files.
     *
     * @param output_interface $output Output handler for progress
     * @return step_result What would be removed
     */
    public function report(output_interface $output): step_result {
        [$sql, $params] = $this->get_candidate_sql();

        $totals = $this->db->get_record_sql(
            sprintf(
                'SELECT COUNT(1) AS records, COALESCE(SUM(candidates.filesize), 0) AS bytes FROM (%s) candidates',
                $sql
            ),
            $params
        );

        $result = new step_result();
        $result->add((int)$totals->records, (int)$totals->bytes);

        $output->write_line(sprintf('Outdated backups and drafts: %s', $result->summarise()));

        return $result;
    }

    /**
     * Remove the outdated backups and drafts.
     *
     * @param output_interface $output Output handler for progress
     * @return step_result What was removed
     */
    public function execute(output_interface $output): step_result {
        [$sql, $params] = $this->get_candidate_sql();
        $bounded = sprintf(
            'SELECT candidates.id, candidates.filesize
               FROM (%s) candidates
              WHERE candidates.id > :lastid
           ORDER BY candidates.id ASC',
            $sql
        );

        $result = new step_result();
        $ceiling = config::max_records_per_run();
        $lastid = 0;

        do {
            $batch = $this->db->get_records_sql(
                $bounded,
                array_merge($params, ['lastid' => $lastid]),
                0,
                self::BATCH_SIZE
            );

            foreach ($batch as $row) {
                $lastid = (int)$row->id;
                $file = $this->fs->get_file_by_id($row->id);

                if ($file === false) {
                    // Gone between the query and now.
                    continue;
                }

                $this->remove($file, $output);
                $result->add(1, (int)$row->filesize);
                $output->write('.');

                if ($ceiling > 0 && $result->get_records() >= $ceiling) {
                    $result->note('Reached the per-run limit; the rest waits for the next run.');

                    break 2;
                }
            }
        } while (count($batch) === self::BATCH_SIZE);

        $output->write_line($result->summarise());

        return $result;
    }

    /**
     * The query behind both paths: outdated backups and drafts nothing else references.
     *
     * Expressed in SQL rather than decided file by file. The previous version opened every
     * file record on the site and asked three questions about each, which the scheduled task
     * now does on every run because reporting is the default - on a large pool that is hours
     * of work to produce a number. It also asked the expensive question first, counting
     * records sharing a content hash before checking the cheap conditions that could rule the
     * file out.
     *
     * @return array The query and its parameters
     */
    private function get_candidate_sql(): array {
        $backup = $this->db->sql_like('f.filename', ':backupsuffix', false);

        $sql = "SELECT f.id, f.filesize
                  FROM {files} f
                 WHERE (
                         ($backup AND f.timecreated <= :backupcutoff)
                      OR (f.filearea = :draftarea AND f.timecreated <= :draftcutoff)
                       )
                   AND NOT EXISTS (
                         SELECT 1
                           FROM {files} o
                          WHERE o.contenthash = f.contenthash
                            AND o.id <> f.id
                       )";

        return [
            $sql,
            [
                'backupsuffix' => '%' . $this->db->sql_like_escape('.mbz'),
                'backupcutoff' => time() - $this->backuptimeout,
                'draftarea' => 'draft',
                'draftcutoff' => time() - $this->drafttimeout,
            ],
        ];
    }

    /**
     * Remove the pool file, where there is one, and then the record.
     *
     * @param stored_file $file File to remove
     * @param output_interface $output Output handler for progress
     * @return void
     */
    private function remove(stored_file $file, output_interface $output): void {
        $handle = $this->fs->get_file_system()->get_content_file_handle($file);

        if ($handle !== false) {
            $uri = stream_get_meta_data($handle)['uri'];
            fclose($handle);

            if (!unlink($uri)) {
                $output->write_line(sprintf('Could not unlink "%s".', $uri));
            }
        }

        $this->db->delete_records('files', ['id' => $file->get_id()]);
    }
}
