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

namespace local_cleanup\task;

use core\task\scheduled_task;
use local_cleanup\config;
use local_cleanup\output\mtrace_output;
use local_cleanup\step\step_interface;
use local_cleanup\step\component_files_cleanup;
use local_cleanup\step\course_modules_cleanup;
use local_cleanup\step\files_checkout;
use local_cleanup\step\ghost_files_cleanup;
use local_cleanup\step\grades_cleanup;
use local_cleanup\step\logs_cleanup;

/**
 * Scheduled task for database and disk cleanup.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends scheduled_task {
    /**
     * Get the name of the task.
     *
     * @return string The name of the task
     */
    public function get_name() {
        return 'Database and disk clean-up';
    }

    /**
     * Execute the task.
     *
     * Runs all configured cleanup steps.
     *
     * @return void
     */
    public function execute() {
        $output = new mtrace_output();

        foreach ($this->get_steps() as $step) {
            $step->cleanup($output);
        }
    }

    /**
     * Build the steps this run should perform.
     *
     * Deliberately not done in the constructor. Moodle instantiates every scheduled task when
     * it re-registers a component's tasks during upgrade, at a point where the plugin's own
     * classes may not be loadable yet, so a constructor that reads configuration breaks the
     * upgrade that installs it.
     *
     * @return step_interface[] Steps to run, in order
     */
    private function get_steps(): array {
        global $CFG, $DB;

        $steps = [];

        if (config::autoremove_enabled()) {
            $steps[] = new course_modules_cleanup($DB, config::course_modules_lifetime_days());
            $steps[] = new grades_cleanup($DB, config::grades_lifetime_days());
            $steps[] = new logs_cleanup($DB, config::logs_lifetime_days());

            // Nothing is cleaned up per component until an administrator names one, so an
            // empty list means this step has no work rather than a default set of victims.
            $components = config::component_files();

            if (!empty($components)) {
                $steps[] = new component_files_cleanup(
                    $DB,
                    $components,
                    config::component_files_lifetime_days()
                );
            }

            $steps[] = new ghost_files_cleanup($DB, $CFG->dataroot);
            $steps[] = new files_checkout(
                $DB,
                get_file_storage(),
                config::backup_lifetime_days(),
                config::draft_lifetime_days()
            );
        }

        return $steps;
    }
}
