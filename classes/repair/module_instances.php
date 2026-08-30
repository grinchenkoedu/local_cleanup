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

namespace local_cleanup\repair;

use Throwable;
use core_component;
use local_cleanup\output\output_interface;
use local_cleanup\step_result;
use moodle_database;
use moodle_recordset;
use stdClass;

/**
 * Removes activities that are left with no course module pointing at them.
 *
 * An activity row with no course module is unreachable from the site, but not from cron: a
 * module's scheduled task selects straight from its own table, and the first row it cannot
 * resolve fails the task for the whole site. Assignments are where it shows, because
 * assign::cron() resolves every row it picks up with MUST_EXIST.
 *
 * This is repair, not maintenance. It is deliberately not a step_interface implementation and
 * is not built by step_factory: nothing here runs from cron. The only way in is
 * cli/fix_orphaned_instances.php, run by hand, which reports unless it is given --execute.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class module_instances {
    /**
     * Days an activity must have sat untouched before it is treated as stranded.
     *
     * Core creates the activity and its course module in two steps, and a restore does the
     * same, so an activity with no course module is a normal state for a moment. This is what
     * keeps the repair away from one that is mid-creation.
     */
    const DEFAULT_GRACE_DAYS = 7;

    /**
     * How many ids a report names per module before it just counts the rest.
     */
    const REPORT_ID_LIMIT = 20;

    /**
     * Database connection.
     *
     * @var moodle_database
     */
    private $db;

    /**
     * Days an activity must have sat untouched to be a candidate.
     *
     * @var int
     */
    private $daystokeep;

    /**
     * Module names to look at, or empty for every installed module.
     *
     * @var string[]
     */
    private $modules;

    /**
     * Course to restrict the search to, or null for all of them.
     *
     * @var int|null
     */
    private $courseid;

    /**
     * Single activity to act on, or null for all of them.
     *
     * @var int|null
     */
    private $instanceid;

    /**
     * Most activities to remove in one run, or zero for no ceiling.
     *
     * @var int
     */
    private $limit;

    /**
     * Constructor.
     *
     * @param moodle_database $db Database connection
     * @param int $daystokeep Days an activity must have sat untouched to be a candidate
     * @param string[] $modules Module names to look at, empty for every installed module
     * @param int|null $courseid Course to restrict the search to, null for all
     * @param int|null $instanceid Single activity to act on, null for all
     * @param int $limit Most activities to remove in one run, zero for no ceiling
     */
    public function __construct(
        moodle_database $db,
        int $daystokeep = self::DEFAULT_GRACE_DAYS,
        array $modules = [],
        ?int $courseid = null,
        ?int $instanceid = null,
        int $limit = 0
    ) {
        $this->db = $db;
        $this->daystokeep = $daystokeep;
        $this->modules = $modules;
        $this->courseid = $courseid;
        $this->instanceid = $instanceid;
        $this->limit = $limit;
    }

    /**
     * Count and name what would be removed, touching nothing.
     *
     * A ceiling on the run does not apply here: the point of the report is the whole number,
     * so an operator can see how many runs the backlog will take.
     *
     * @param output_interface $output Output handler for progress
     * @return step_result What would be removed
     */
    public function report(output_interface $output): step_result {
        $result = new step_result();

        foreach ($this->get_modules($output) as $module) {
            $found = 0;
            $gone = 0;
            $named = [];
            $orphans = $this->get_orphans($module);

            try {
                foreach ($orphans as $orphan) {
                    $found++;

                    if (empty($orphan->courseexists)) {
                        $gone++;
                    }

                    if (count($named) < self::REPORT_ID_LIMIT) {
                        $named[] = sprintf(
                            '%d (course %d%s)',
                            $orphan->id,
                            $orphan->course,
                            empty($orphan->courseexists) ? ', gone' : ''
                        );
                    }
                }
            } finally {
                $orphans->close();
            }

            if ($found === 0) {
                continue;
            }

            $result->add($found);
            $output->write_line(sprintf(
                '%s: %d stranded activity(s) - %d in a course that still exists, %d in a course that is gone.',
                $module->name,
                $found,
                $found - $gone,
                $gone
            ));
            $output->write_line(sprintf(
                '  ids: %s%s',
                implode(', ', $named),
                $found > count($named) ? sprintf(', and %d more', $found - count($named)) : ''
            ));
        }

        if ($result->is_empty()) {
            $output->write_line('No stranded activities found.');
        }

        return $result;
    }

    /**
     * Remove them.
     *
     * One activity failing does not stop the rest: a module that cannot be deleted is reported
     * and left exactly as it was.
     *
     * @param output_interface $output Output handler for progress
     * @return step_result What was removed
     */
    public function execute(output_interface $output): step_result {
        global $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        $result = new step_result();

        foreach ($this->get_modules($output) as $module) {
            $remaining = $this->limit > 0 ? $this->limit - $result->get_records() : 0;

            if ($this->limit > 0 && $remaining <= 0) {
                $result->note('Reached the per-run limit; the rest waits for the next run.');

                break;
            }

            // Read the whole list before deleting any of it. Deleting an activity rebuilds the
            // course cache and fires events, which is not something to do underneath an open
            // recordset on the table being deleted from.
            $pending = [];
            $orphans = $this->get_orphans($module, $remaining);

            try {
                foreach ($orphans as $orphan) {
                    $pending[] = $orphan;
                }
            } finally {
                $orphans->close();
            }

            foreach ($pending as $orphan) {
                $output->write(sprintf(
                    'Removing %s %d from course %d... ',
                    $module->name,
                    $orphan->id,
                    $orphan->course
                ));

                try {
                    if (empty($orphan->courseexists)) {
                        $this->delete_unreachable($module, $orphan);
                    } else {
                        $this->delete_through_core($module, $orphan);
                    }

                    $result->add(1);
                    $output->write_line('OK');
                } catch (Throwable $e) {
                    // Deliberately broad, for the same reason the stuck modules step is: an
                    // activity nobody can delete through the interface is exactly the kind
                    // that raises something other than an exception on the way out.
                    $output->write_line('Failed: ' . $e->getMessage());
                }
            }
        }

        return $result;
    }

    /**
     * The modules worth looking at, with everything unusable reported and dropped.
     *
     * @param output_interface $output Output handler for progress
     * @return stdClass[] Module records, each with an id and a name
     */
    private function get_modules(output_interface $output): array {
        $installed = core_component::get_plugin_list('mod');
        $manager = $this->db->get_manager();
        $scope = [];

        foreach ($this->db->get_records('modules', null, 'name', 'id, name') as $module) {
            if (!empty($this->modules) && !in_array($module->name, $this->modules, true)) {
                continue;
            }

            // The name becomes a table name in the query below, where a placeholder cannot
            // reach. Two checks stand in for one: it has to survive PARAM_PLUGIN unchanged,
            // and it has to name a module that is actually installed on disk.
            if ($module->name !== clean_param($module->name, PARAM_PLUGIN) || !isset($installed[$module->name])) {
                $output->write_line(sprintf('%s: not an installed activity module, skipped.', $module->name));

                continue;
            }

            if (!$manager->table_exists($module->name)) {
                $output->write_line(sprintf('%s: has no table of its own, skipped.', $module->name));

                continue;
            }

            // Every module in core has both, but a third-party one need not, and without them
            // there is no way to tell which course an activity belonged to or how old it is.
            foreach (['course', 'timemodified'] as $field) {
                if (!$manager->field_exists($module->name, $field)) {
                    $output->write_line(sprintf('%s: table has no %s column, skipped.', $module->name, $field));

                    continue 2;
                }
            }

            $scope[] = $module;
        }

        return $scope;
    }

    /**
     * Activities of one module with no course module pointing at them.
     *
     * The module id in the join matters: instance ids are only unique within a module table, so
     * without it an assignment matches the course module of a quiz that happens to share a
     * number.
     *
     * Rows belonging to no course at all are not stranded activities and are never candidates.
     * Surveys are the reason: mod_survey installs five template rows with course = 0, no course
     * module and a timemodified from 2001, and deleting those would take the site's ability to
     * create a survey with them.
     *
     * @param stdClass $module Module record, with an id and a name
     * @param int $limit Most rows to read, or zero for all of them
     * @return moodle_recordset Rows of id, course and courseexists
     */
    private function get_orphans(stdClass $module, int $limit = 0): moodle_recordset {
        $params = [
            'moduleid' => $module->id,
            'cutoff' => time() - ($this->daystokeep * DAYSECS),
        ];
        $conditions = '';

        if ($this->courseid !== null) {
            // Deliberately the activity's own course column rather than a join: it still holds
            // the id after the course itself has been deleted, which is when it is most needed.
            $conditions .= ' AND i.course = :courseid';
            $params['courseid'] = $this->courseid;
        }

        if ($this->instanceid !== null) {
            $conditions .= ' AND i.id = :instanceid';
            $params['instanceid'] = $this->instanceid;
        }

        // The table name is the one value here that cannot be a placeholder, and get_modules()
        // has already checked it against the modules installed on disk.
        $sql = sprintf(
            "SELECT i.id, i.course, c.id AS courseexists
               FROM {%s} i
          LEFT JOIN {course_modules} cm ON cm.instance = i.id AND cm.module = :moduleid
          LEFT JOIN {course} c ON c.id = i.course
              WHERE cm.id IS NULL
                AND i.course > 0
                AND i.timemodified < :cutoff%s
           ORDER BY i.id ASC",
            $module->name,
            $conditions
        );

        return $this->db->get_recordset_sql($sql, $params, 0, $limit);
    }

    /**
     * Give the activity its course module back, then let core delete it properly.
     *
     * Core has no way to delete an activity that has no course module - every module's
     * delete_instance() finds the activity through one - so the course module is put back
     * first, hidden, and course_delete_module() does the rest: the instance, its grades, its
     * files, its calendar events, its tags, its context.
     *
     * It is marked for deletion from the moment it exists. If anything below fails, what is
     * left behind is a stuck course module, which the stuck modules step and
     * cli/reinit_modules_cleanup.php both know how to finish, rather than a hidden activity
     * nobody was expecting.
     *
     * @param stdClass $module Module record, with an id and a name
     * @param stdClass $orphan Activity row, with an id and a course
     * @return void
     */
    private function delete_through_core(stdClass $module, stdClass $orphan): void {
        $newcm = new stdClass();
        $newcm->course = $orphan->course;
        $newcm->module = $module->id;
        $newcm->instance = $orphan->id;
        $newcm->section = 0;
        $newcm->visible = 0;
        $newcm->visibleold = 0;
        $newcm->deletioninprogress = 1;

        $cmid = add_course_module($newcm);

        course_add_cm_to_section($orphan->course, $cmid, 0);
        course_delete_module($cmid);
    }

    /**
     * Delete an activity whose course has gone as well.
     *
     * With no course there is no course context, so the course module cannot be rebuilt and
     * core cannot be asked to do this. The row and the calendar entries pointing at it are
     * removed directly.
     *
     * The module's own child rows are left behind. They reference an activity that no longer
     * exists, which breaks nothing, and the alternative - a per-module list of table names -
     * could never be complete: assignments alone would need six, plus every assignsubmission
     * and assignfeedback subplugin. Grade items for a dead course are already covered by the
     * grades step, and its contexts by core's own context clean-up task.
     *
     * @param stdClass $module Module record, with an id and a name
     * @param stdClass $orphan Activity row, with an id and a course
     * @return void
     */
    private function delete_unreachable(stdClass $module, stdClass $orphan): void {
        $this->db->delete_records('event', ['modulename' => $module->name, 'instance' => $orphan->id]);
        $this->db->delete_records($module->name, ['id' => $orphan->id]);
    }
}
