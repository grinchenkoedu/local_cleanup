<?php

namespace local_cleanup\steps;

use Exception;
use local_cleanup\output\OutputInterface;
use moodle_database;

class CourseModulesCleanup implements CleanupStepInterface
{
    const DEFAULT_LIFETIME_DAYS = 7;

    private moodle_database $db;

    /**
     * @var int Number of days to keep course modules
     */
    private $daysToKeep;

    public function __construct(moodle_database $db, int $daysToKeep = self::DEFAULT_LIFETIME_DAYS)
    {
        $this->db = $db;
        $this->daysToKeep = $daysToKeep;
    }

    public function cleanUp(OutputInterface $output)
    {
        global $CFG;

        $this->cleanUpOrphanedCourseModules($output);

        $cutoffTime = time() - ($this->daysToKeep * 24 * 60 * 60);

        $tasks = $this->db->get_records_select(
            'task_adhoc',
            'classname = ? AND faildelay > 0 AND timestarted < ?',
            ['\core_course\task\course_delete_modules', $cutoffTime]
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
                    $this->deleteCourseModule($cm->id, $output);
                } catch (Exception $e) {
                    $output->writeLine(sprintf('Failed to delete course module %d: %s', $cm->id, $e->getMessage()));
                    $success = false;
                }
            }

            if ($success) {
                $this->db->delete_records('task_adhoc', ['id' => $task->id]);
            }
        }
    }

    private function deleteCourseModule(int $id, OutputInterface $output)
    {
        $output->write(sprintf('Deleting course module %d...', $id));

        $cm = $this->db->get_record('course_modules', ['id' => $id]);

        if (!$cm) {
            $output->writeLine('Failed: Course module not found.');

            return;
        }

        try {
            course_delete_module($cm->id);
        } catch (Exception $e) {
            $output->writeLine('Failed: ' . $e->getMessage());

            if ($e instanceof \moodle_exception && $e->errorcode == 'cannotdeletemodulemissinglib') {
                return;
            }

            $output->write('Failed to remove normally. Now trying to clean-up... ');
            $this->cleanUpCourseModuleData($cm);
        }

        $output->writeLine('OK');
    }

    private function cleanUpOrphanedCourseModules(OutputInterface $output): void
    {
        global $CFG;

        $output->writeLine('Checking for course modules tied to deleted courses...');

        $sql = "SELECT cm.* 
                FROM {course_modules} cm 
                LEFT JOIN {course} c ON cm.course = c.id 
                WHERE c.id IS NULL";

        $orphanedModules = $this->db->get_records_sql($sql);

        if (empty($orphanedModules)) {
            $output->writeLine('No orphaned course modules found.');
            return;
        }

        $output->writeLine(sprintf('Found %d orphaned course modules. Cleaning up...', count($orphanedModules)));

        require_once($CFG->dirroot . '/course/lib.php');

        foreach ($orphanedModules as $cm) {
            try {
                $this->deleteCourseModule($cm->id, $output);
            } catch (Exception $e) {
                $output->writeLine(sprintf('Failed to delete orphaned course module %d: %s', $cm->id, $e->getMessage()));
            }
        }

        $output->writeLine('Orphaned course modules cleanup completed.');
    }

    /**
     * This is the clean-up part of the course_delete_module function, Moodle 4.1
     * @param object $cm
     * @see course_delete_module
     */
    private function cleanUpCourseModuleData($cm): void
    {
        $modcontext = \context_module::instance($cm->id);
        $modulename = $this->db->get_field('modules', 'name', array('id' => $cm->module), MUST_EXIST);

        question_delete_activity($cm);

        // Remove all module files in case modules forget to do that.
        $fs = get_file_storage();
        $fs->delete_area_files($modcontext->id);

        // Delete events from calendar.
        if ($events = $this->db->get_records('event', array('instance' => $cm->instance, 'modulename' => $modulename))) {
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
        $this->db->delete_records('course_modules_completion', array('coursemoduleid' => $cm->id));
        $this->db->delete_records('course_modules_viewed', ['coursemoduleid' => $cm->id]);
        $this->db->delete_records('course_completion_criteria', array('moduleinstance' => $cm->id,
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
        $this->db->delete_records('course_modules', array('id' => $cm->id));

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
