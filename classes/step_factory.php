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

namespace local_cleanup;

use local_cleanup\output\output_interface;
use local_cleanup\step\component_files_cleanup;
use local_cleanup\step\course_modules_cleanup;
use local_cleanup\step\files_checkout;
use local_cleanup\step\ghost_files_cleanup;
use local_cleanup\step\grades_cleanup;
use local_cleanup\step\logs_cleanup;
use local_cleanup\step\step_interface;

/**
 * Builds the clean-up steps from the site's configuration.
 *
 * One definition of the run, shared by the scheduled task and the command line, so a dry run
 * an operator performs by hand covers exactly what cron would.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_factory {
    /**
     * Build every step this site is configured for, in the order they should run.
     *
     * @return step_interface[] The steps
     */
    public static function create_all(): array {
        global $CFG, $DB;

        $steps = [
            new course_modules_cleanup($DB, config::course_modules_lifetime_days()),
            new grades_cleanup($DB, config::grades_lifetime_days()),
            new logs_cleanup($DB, config::logs_lifetime_days()),
        ];

        // Nothing is cleaned up per component until an administrator names one, so an empty
        // list means this step has no work rather than a default set of victims.
        $components = config::component_files();

        if (!empty($components)) {
            $steps[] = new component_files_cleanup($DB, $components, config::component_files_lifetime_days());
        }

        $steps[] = new ghost_files_cleanup($DB, $CFG->dataroot, config::ghost_grace_days());
        $steps[] = new files_checkout(
            $DB,
            get_file_storage(),
            config::backup_lifetime_days(),
            config::draft_lifetime_days()
        );

        return $steps;
    }

    /**
     * Run every step, reporting or removing, and total up what happened.
     *
     * @param output_interface $output Output handler for progress
     * @param bool $remove Whether to remove anything, or only count it
     * @return step_result The totals across every step
     */
    public static function run_all(output_interface $output, bool $remove): step_result {
        $total = new step_result();

        foreach (self::create_all() as $step) {
            $output->write_line(sprintf('== %s ==', $step->get_name()));

            $total->merge($remove ? $step->execute($output) : $step->report($output));
        }

        foreach ($total->get_notes() as $note) {
            $output->write_line($note);
        }

        $output->write_line(sprintf(
            '%s: %s',
            $remove ? 'Removed' : 'Would remove',
            $total->summarise()
        ));

        return $total;
    }
}
