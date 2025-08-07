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

namespace local_cleanup\steps;

use Exception;
use local_cleanup\output\OutputInterface;
use moodle_database;

/**
 * Course modules cleanup step.
 *
 * Handles cleanup of orphaned course modules and failed course module deletion tasks.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class CourseModulesCleanup implements CleanupStepInterface {

    /**
     * Default number of days to keep course modules.
     */
    const DEFAULT_LIFETIME_DAYS = 7;

    /**
     * Database connection.
     *
     * @var moodle_database
     */
    private $db;

    /**
     * @var int Number of days to keep course modules
     */
    private $daystokeep;

    /**
     * Constructor.
     *
     * @param moodle_database $db Database connection
     * @param int $daystokeep Number of days to keep course modules
     */
    public function __construct(moodle_database $db, int $daystokeep = self::DEFAULT_LIFETIME_DAYS) {
        $this->db = $db;
        $this->daystokeep = $daystokeep;
    }

    /**
     * Execute the cleanup step.
     *
     * Cleans up orphaned course modules and failed course module deletion tasks.
     *
     * @param OutputInterface $output Output handler for logging
     * @return void
     */
    public function cleanup(OutputInterface $output) {
        global $CFG;

        $this->cleanUpOrphanedCourseModules($output);

        $cutofftime = time() - ($this->daystokeep * 24 * 60 * 60);

        $tasks = $this->db->get_records_select(
            'task_adhoc',
            'classname = ? AND faildelay > 0 AND timestarted < ?',
            ['\core_course\task\course_delete_modules', $cutofftime]
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

    /**
     * Delete a course module by ID.
     *
     * Attempts to delete a course module using the standard Moodle function,
     * and falls back to manual cleanup if that fails.
     *
     * @param int $id Course module ID
     * @param OutputInterface $output Output handler for logging
     * @return void
     */
    private function deletecoursemodule(int $id, OutputInterface $output) {
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

    /**
     * Clean up course modules that are tied to deleted courses.
     *
     * Identifies and removes course modules that reference courses that no longer exist.
     *
     * @param OutputInterface $output Output handler for logging
     * @return void
     */
    private function cleanuporphanedcoursemodules(OutputInterface $output): void {
        global $CFG;

        $output->writeLine('Checking for course modules tied to deleted courses...');

        $sql = "SELECT cm.* "
                . "FROM {course_modules} cm "
                . "LEFT JOIN {course} c ON cm.course = c.id "
                . "WHERE c.id IS NULL";

        $orphanedmodules = $this->db->get_records_sql($sql);

        if (empty($orphanedmodules)) {
            $output->writeLine('No orphaned course modules found.');
            return;
        }

        $output->writeLine(sprintf('Found %d orphaned course modules. Cleaning up...', count($orphanedmodules)));

        require_once($CFG->dirroot . '/course/lib.php');

        foreach ($orphanedmodules as $cm) {
            try {
                $this->deleteCourseModule($cm->id, $output);
            } catch (Exception $e) {
                $output->writeLine(sprintf('Failed to delete orphaned course module %d: %s', $cm->id, $e->getMessage()));
            }
        }

        $output->writeLine('Orphaned course modules cleanup completed.');
    }

    /**
     * Manually clean up course module data when standard deletion fails.
     *
     * This is based on the clean-up part of the course_delete_module function in Moodle 4.1.
     * It removes all associated data for a course module when the standard deletion process fails.
     *
     * @param object $cm Course module object
     * @return void
     * @see course_delete_module
     */
    private function cleanupcoursemoduledata($cm): void {
        $modcontext = \context_module::instance($cm->id);
        $modulename = $this->db->get_field('modules', 'name', ['id' => $cm->module], MUST_EXIST);

        question_delete_activity($cm);

        // Remove all module files in case modules forget to do that.
        $fs = get_file_storage();
        $fs->delete_area_files($modcontext->id);

        // Delete events from calendar.
        if ($events = $this->db->get_records('event', ['instance' => $cm->instance, 'modulename' => $modulename])) {
            $coursecontext = \context_course::instance($cm->course);
            foreach ($events as $event) {
                $event->context = $coursecontext;
                $calendarevent = \calendar_event::load($event);
                $calendarevent->delete();
            }
        }

        // Delete grade items, outcome items and grades attached to modules.
        if ($gradeitems = \grade_item::fetch_all(['itemtype' => 'mod', 'itemmodule' => $modulename,
            'iteminstance' => $cm->instance, 'courseid' => $cm->course])) {
            foreach ($gradeitems as $gradeitem) {
                $gradeitem->delete('moddelete');
            }
        }

        // Delete associated blogs and blog tag instances.
        blog_remove_associations_for_module($modcontext->id);

        // Delete completion and availability data; it is better to do this even if the
        // features are not turned on, in case they were turned on previously (these will be
        // very quick on an empty table).
        $this->db->delete_records('course_modules_completion', ['coursemoduleid' => $cm->id]);
        $this->db->delete_records('course_modules_viewed', ['coursemoduleid' => $cm->id]);
        $this->db->delete_records('course_completion_criteria', ['moduleinstance' => $cm->id,
            'course' => $cm->course,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY]);

        // Delete all tag instances associated with the instance of this module.
        \core_tag_tag::delete_instances('mod_' . $modulename, null, $modcontext->id);
        \core_tag_tag::remove_all_item_tags('core', 'course_modules', $cm->id);

        // Notify the competency subsystem.
        \core_competency\api::hook_course_module_deleted($cm);

        // Delete the context.
        \context_helper::delete_instance(CONTEXT_MODULE, $cm->id);

        // Delete the module from the course_modules table.
        $this->db->delete_records('course_modules', ['id' => $cm->id]);

        // Delete module from that section.
        if (!delete_mod_from_section($cm->id, $cm->section)) {
            throw new \moodle_exception('cannotdeletemodulefromsection', '', '', null,
                "Cannot delete the module $modulename (instance) from section.");
        }

        // Trigger event for course module delete action.
        $event = \core\event\course_module_deleted::create([
            'courseid' => $cm->course,
            'context'  => $modcontext,
            'objectid' => $cm->id,
            'other'    => [
                'modulename'   => $modulename,
                'instanceid'   => $cm->instance,
            ],
        ]);
        $event->add_record_snapshot('course_modules', $cm);
        $event->trigger();
        \course_modinfo::purge_course_module_cache($cm->course, $cm->id);
        rebuild_course_cache($cm->course, false, true);
    }
}
