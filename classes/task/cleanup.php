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
use local_cleanup\step_factory;

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
     * Reporting is the default. The task only removes anything when an administrator has
     * turned automatic removal on; otherwise it prints what it would have removed, which is
     * how an operator gets the numbers before agreeing to them.
     *
     * @return void
     */
    public function execute() {
        $output = new mtrace_output();
        $remove = config::autoremove_enabled();

        if (!$remove) {
            $output->write_line(
                'Automatic removal is disabled. Reporting what would be removed; nothing is deleted.'
            );
        }

        step_factory::run_all($output, $remove);
    }
}
