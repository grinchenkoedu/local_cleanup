<?php

namespace local_cleanup\task;

use core\task\scheduled_task;
use moodle_database;
use file_storage;

class cleanup extends scheduled_task
{
    const SELECT_ALL = '';
    const SECONDS_IN_MONTH = 2592000;

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

    private $isCourseModulesDeletionEnabled;

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
        $this->backupTimeout = $CFG->cleanup_backup_timeout ?? self::SECONDS_IN_MONTH;
        $this->draftTimeout = $CFG->cleanup_draft_timeout ?? self::SECONDS_IN_MONTH;
        $this->isAutoRemoveEnabled = (bool)$CFG->cleanup_run_autoremove ?? false;
        $this->isCourseModulesDeletionEnabled = (bool)$CFG->cleanup_delete_course_modules ?? false;
        $this->fs = get_file_storage();
    }

    public function get_name()
    {
        return 'Database and disk clean-up';
    }

    public function execute()
    {
        if ($this->isCourseModulesDeletionEnabled) {
            $this->deleteStuckCourseModules();
        }

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

    private function deleteStuckCourseModules(): void
    {
        global $DB, $CFG;

        $tasks = $DB->get_records_select(
            'task_adhoc',
            'classname = ? AND faildelay > 0',
            ['\core_course\task\course_delete_modules']
        );

        if (count($tasks) === 0) {
            return;
        }

        require_once($CFG->dirroot . '/course/lib.php');

        foreach ($tasks as $task) {
            $success = true;
            $customdata = json_decode($task->customdata);

            foreach ($customdata->cms as $cm) {
                try {
                    $this->deleteCourseModule($cm->id);
                } catch (\Exception $e) {
                    mtrace(sprintf('Failed to delete course module %d: %s', $cm->id, $e->getMessage()));
                    $success = false;
                }
            }

            if ($success) {
                $DB->delete_records('task_adhoc', ['id' => $task->id]);
            }
        }
    }

    private function deleteCourseModule(int $id)
    {
        global $DB;

        mtrace(sprintf('Deleting course module %d...', $id), null);

        $cm = $DB->get_record('course_modules', ['id' => $id]);

        if (!$cm) {
            mtrace('Failed: Course module not found.');

            return;
        }

        try {
            course_delete_module($cm->id);
        } catch (\Exception $e) {
            mtrace('Failed: ' . $e->getMessage());

            if ($e instanceof \moodle_exception && $e->errorcode == 'cannotdeletemodulemissinglib') {
                return;
            }

            mtrace('Failed to remove normally. Now trying to clean-up... ', null);
            $this->cleanUpCourseModuleData($cm);
        }

        mtrace('OK');
    }

    /**
     * This is the clean-up part of the course_delete_module function, Moodle 4.1
     * @param object $cm
     * @see course_delete_module
     */
    private function cleanUpCourseModuleData($cm): void
    {
        global $DB;

        $modcontext = \context_module::instance($cm->id);
        $modulename = $DB->get_field('modules', 'name', array('id' => $cm->module), MUST_EXIST);

        question_delete_activity($cm);

        // Remove all module files in case modules forget to do that.
        $fs = get_file_storage();
        $fs->delete_area_files($modcontext->id);

        // Delete events from calendar.
        if ($events = $DB->get_records('event', array('instance' => $cm->instance, 'modulename' => $modulename))) {
            $coursecontext = \context_course::instance($cm->course);
            foreach($events as $event) {
                $event->context = $coursecontext;
                $calendarevent = \calendar_event::load($event);
                $calendarevent->delete();
            }
        }

        // Delete grade items, outcome items and grades attached to modules.
        if ($grade_items = \grade_item::fetch_all(array('itemtype' => 'mod', 'itemmodule' => $modulename,
            'iteminstance' => $cm->instance, 'courseid' => $cm->course))) {
            foreach ($grade_items as $grade_item) {
                $grade_item->delete('moddelete');
            }
        }

        // Delete associated blogs and blog tag instances.
        blog_remove_associations_for_module($modcontext->id);

        // Delete completion and availability data; it is better to do this even if the
        // features are not turned on, in case they were turned on previously (these will be
        // very quick on an empty table).
        $DB->delete_records('course_modules_completion', array('coursemoduleid' => $cm->id));
        $DB->delete_records('course_modules_viewed', ['coursemoduleid' => $cm->id]);
        $DB->delete_records('course_completion_criteria', array('moduleinstance' => $cm->id,
            'course' => $cm->course,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY));

        // Delete all tag instances associated with the instance of this module.
        \core_tag_tag::delete_instances('mod_' . $modulename, null, $modcontext->id);
        \core_tag_tag::remove_all_item_tags('core', 'course_modules', $cm->id);

        // Notify the competency subsystem.
        \core_competency\api::hook_course_module_deleted($cm);

        // Delete the context.
        \context_helper::delete_instance(CONTEXT_MODULE, $cm->id);

        // Delete the module from the course_modules table.
        $DB->delete_records('course_modules', array('id' => $cm->id));

        // Delete module from that section.
        if (!delete_mod_from_section($cm->id, $cm->section)) {
            throw new \moodle_exception('cannotdeletemodulefromsection', '', '', null,
                "Cannot delete the module $modulename (instance) from section.");
        }

        // Trigger event for course module delete action.
        $event = \core\event\course_module_deleted::create(array(
            'courseid' => $cm->course,
            'context'  => $modcontext,
            'objectid' => $cm->id,
            'other'    => array(
                'modulename'   => $modulename,
                'instanceid'   => $cm->instance,
            )
        ));
        $event->add_record_snapshot('course_modules', $cm);
        $event->trigger();
        \course_modinfo::purge_course_module_cache($cm->course, $cm->id);
        rebuild_course_cache($cm->course, false, true);
    }
}
