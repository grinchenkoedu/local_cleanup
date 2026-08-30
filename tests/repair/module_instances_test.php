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

use advanced_testcase;
use local_cleanup\output\spy_output;
use stdClass;

/**
 * Tests for the stranded activities repair.
 *
 * This removes activities, so what it leaves alone matters as much as what it deletes: an
 * activity that still has a course module is in use, and one that has none yet is very likely
 * mid-creation rather than stranded.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_cleanup\repair\module_instances
 */
final class module_instances_test extends advanced_testcase {
    /**
     * Days an activity must have sat untouched, for these tests.
     */
    const GRACE_DAYS = 7;

    /**
     * Load the output spy, which lives outside the autoloaded class directory.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        require_once(__DIR__ . '/../fixtures/spy_output.php');

        parent::setUpBeforeClass();
    }

    /**
     * An activity with no course module behind it is found.
     *
     * @return void
     */
    public function test_a_stranded_activity_is_reported(): void {
        $this->resetAfterTest();

        $this->strand('page');

        $output = new spy_output();
        $result = $this->repair()->report($output);

        $this->assertSame(1, $result->get_records());
        $this->assertTrue(
            $output->contains('page: 1 stranded activity(s) - 1 in a course that still exists, 0 in a course that is gone.'),
            'The stranded page should be counted against its module, in the live course column.'
        );
    }

    /**
     * An activity that still has its course module is not stranded.
     *
     * @return void
     */
    public function test_a_healthy_activity_is_not_reported(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $output = new spy_output();
        $result = $this->repair()->report($output);

        $this->assertSame(0, $result->get_records());
        $this->assertTrue($output->contains('No stranded activities found.'));
    }

    /**
     * An activity that has just been touched is left alone.
     *
     * Having no course module yet is a normal state part-way through creating an activity or
     * restoring a course, which is the whole reason for the grace period.
     *
     * @return void
     */
    public function test_an_activity_inside_the_grace_period_is_left_alone(): void {
        global $DB;

        $this->resetAfterTest();

        $module = $this->strand('page');
        $DB->set_field('page', 'timemodified', time(), ['id' => $module->id]);

        $this->assertSame(0, $this->repair()->report(new spy_output())->get_records());
        $this->assertSame(0, $this->repair()->execute(new spy_output())->get_records());
        $this->assertTrue($DB->record_exists('page', ['id' => $module->id]), 'It is too new to be stranded.');
    }

    /**
     * A dry run counts the work and does none of it.
     *
     * @return void
     */
    public function test_a_report_removes_nothing(): void {
        global $DB;

        $this->resetAfterTest();

        $module = $this->strand('page');

        $this->repair()->report(new spy_output());

        $this->assertTrue($DB->record_exists('page', ['id' => $module->id]), 'A dry run must remove nothing.');
    }

    /**
     * The report names what it found, so the next run can be narrowed to it.
     *
     * @return void
     */
    public function test_the_report_names_what_it_found(): void {
        $this->resetAfterTest();

        $module = $this->strand('page');

        $output = new spy_output();
        $this->repair()->report($output);

        $this->assertTrue(
            $output->contains(sprintf('ids: %d (course %d)', $module->id, $module->course)),
            'The report should name the activity and the course it belonged to.'
        );
    }

    /**
     * An activity whose course survives is removed by core, with everything hanging off it.
     *
     * @return void
     */
    public function test_an_activity_in_a_live_course_is_deleted_through_core(): void {
        global $DB;

        $this->resetAfterTest();

        $module = $this->strand('assign');

        $result = $this->repair()->execute(new spy_output());

        $this->assertSame(1, $result->get_records());
        $this->assertFalse($DB->record_exists('assign', ['id' => $module->id]), 'The activity should be gone.');
        $this->assertFalse(
            $DB->record_exists('grade_items', [
                'itemtype' => 'mod',
                'itemmodule' => 'assign',
                'iteminstance' => $module->id,
            ]),
            'Going through course_delete_module() is what takes the grade item with it.'
        );
        $this->assertFalse(
            $DB->record_exists('course_modules', ['module' => $this->module_id('assign'), 'instance' => $module->id]),
            'The course module rebuilt to delete the activity must not outlive it.'
        );
    }

    /**
     * An activity whose course has gone too is removed directly, with its calendar entries.
     *
     * @return void
     */
    public function test_an_activity_whose_course_is_gone_is_deleted_directly(): void {
        global $DB;

        $this->resetAfterTest();

        $module = $this->strand('assign', null, ['duedate' => time() + DAYSECS]);
        $DB->delete_records('course', ['id' => $module->course]);

        $result = $this->repair()->execute(new spy_output());

        $this->assertSame(1, $result->get_records());
        $this->assertFalse($DB->record_exists('assign', ['id' => $module->id]), 'The activity should be gone.');
        $this->assertFalse(
            $DB->record_exists('event', ['modulename' => 'assign', 'instance' => $module->id]),
            'Its calendar entries point at nothing and go with it.'
        );
    }

    /**
     * Naming a module keeps the repair out of every other module table.
     *
     * @return void
     */
    public function test_only_the_named_module_is_touched(): void {
        global $DB;

        $this->resetAfterTest();

        $page = $this->strand('page');
        $assign = $this->strand('assign');

        $result = $this->repair(['page'])->execute(new spy_output());

        $this->assertSame(1, $result->get_records());
        $this->assertFalse($DB->record_exists('page', ['id' => $page->id]));
        $this->assertTrue($DB->record_exists('assign', ['id' => $assign->id]), 'Only pages were asked for.');
    }

    /**
     * Naming a course keeps the repair out of every other course.
     *
     * @return void
     */
    public function test_only_the_named_course_is_touched(): void {
        global $DB;

        $this->resetAfterTest();

        $wanted = $this->strand('page');
        $other = $this->strand('page');

        $result = $this->repair([], (int)$wanted->course)->execute(new spy_output());

        $this->assertSame(1, $result->get_records());
        $this->assertFalse($DB->record_exists('page', ['id' => $wanted->id]));
        $this->assertTrue($DB->record_exists('page', ['id' => $other->id]), 'The other course was not asked for.');
    }

    /**
     * A single activity can be targeted, which is the narrowest repair there is.
     *
     * @return void
     */
    public function test_a_single_activity_can_be_targeted(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $wanted = $this->strand('page', $course);
        $other = $this->strand('page', $course);

        $result = $this->repair(['page'], null, (int)$wanted->id)->execute(new spy_output());

        $this->assertSame(1, $result->get_records());
        $this->assertFalse($DB->record_exists('page', ['id' => $wanted->id]));
        $this->assertTrue($DB->record_exists('page', ['id' => $other->id]), 'Only one instance was asked for.');
    }

    /**
     * A row belonging to no course is a template, not a stranded activity.
     *
     * mod_survey installs five of them - course 0, no course module, a timemodified from 2001 -
     * and deleting those would take the site's ability to create a survey from a template. This
     * uses a page rather than a survey because core dropped mod_survey in 5.0, but the row it
     * builds has the shape that matters.
     *
     * @return void
     */
    public function test_a_row_belonging_to_no_course_is_left_alone(): void {
        global $DB;

        $this->resetAfterTest();

        $module = $this->strand('page');
        $DB->set_field('page', 'course', 0, ['id' => $module->id]);

        $output = new spy_output();
        $result = $this->repair()->report($output);

        $this->assertSame(0, $result->get_records());
        $this->assertTrue($output->contains('No stranded activities found.'));

        $this->repair()->execute(new spy_output());

        $this->assertTrue($DB->record_exists('page', ['id' => $module->id]), 'A template must survive.');
    }

    /**
     * A ceiling bounds one run, and what it does not reach waits for the next.
     *
     * @return void
     */
    public function test_a_limit_bounds_what_one_run_removes(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->strand('page', $course);
        $this->strand('page', $course);

        $repair = new module_instances($DB, self::GRACE_DAYS, ['page'], null, null, 1);
        $result = $repair->execute(new spy_output());

        $this->assertSame(1, $result->get_records());
        $this->assertSame(1, $DB->count_records('page'), 'The second one waits for the next run.');
    }

    /**
     * The report counts the whole backlog, whatever ceiling the run is given.
     *
     * @return void
     */
    public function test_a_limit_does_not_narrow_the_report(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->strand('page', $course);
        $this->strand('page', $course);

        $repair = new module_instances($DB, self::GRACE_DAYS, ['page'], null, null, 1);

        $this->assertSame(2, $repair->report(new spy_output())->get_records());
    }

    /**
     * A module that is registered but not installed is reported and skipped, never queried.
     *
     * @return void
     */
    public function test_a_module_that_is_not_installed_is_skipped(): void {
        global $DB;

        $this->resetAfterTest();

        $DB->insert_record('modules', (object)['name' => 'notarealmodule', 'search' => '', 'visible' => 1]);

        $output = new spy_output();
        $result = $this->repair()->report($output);

        $this->assertSame(0, $result->get_records());
        $this->assertTrue(
            $output->contains('notarealmodule: not an installed activity module, skipped.'),
            'A name with no plugin behind it must never reach the query as a table name.'
        );
    }

    /**
     * Build the repair with the test grace period.
     *
     * @param string[] $modules Module names to look at, empty for all
     * @param int|null $courseid Course to restrict to, null for all
     * @param int|null $instanceid Activity to restrict to, null for all
     * @return module_instances The repair
     */
    private function repair(array $modules = [], ?int $courseid = null, ?int $instanceid = null): module_instances {
        global $DB;

        return new module_instances($DB, self::GRACE_DAYS, $modules, $courseid, $instanceid);
    }

    /**
     * Create an activity and take its course module away, leaving the activity behind.
     *
     * That is the state a deletion which failed part-way through leaves: the course module and
     * everything reached through it are gone, and the activity's own row is not.
     *
     * @param string $modname Module name, such as "page"
     * @param stdClass|null $course Course to create it in, or null for a new one
     * @param array $options Extra options for the module generator
     * @return stdClass The activity, with its id and course
     */
    private function strand(string $modname, ?stdClass $course = null, array $options = []): stdClass {
        global $DB;

        $course = $course ?? $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module($modname, ['course' => $course->id] + $options);

        $DB->delete_records('course_modules', ['id' => $module->cmid]);

        // Age it past the grace period. Everything the generator makes is new, and new is
        // exactly what the repair is written to leave alone.
        $DB->set_field(
            $modname,
            'timemodified',
            time() - ((self::GRACE_DAYS + 1) * DAYSECS),
            ['id' => $module->id]
        );

        return $module;
    }

    /**
     * The id of a module in the modules table.
     *
     * @param string $modname Module name, such as "assign"
     * @return int The module id
     */
    private function module_id(string $modname): int {
        global $DB;

        return (int)$DB->get_field('modules', 'id', ['name' => $modname], MUST_EXIST);
    }
}
