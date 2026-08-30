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

use local_cleanup\output\output_interface;
use local_cleanup\step_result;

/**
 * Interface for cleanup steps.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface step_interface {
    /**
     * Name this step, for the operator reading a report.
     *
     * @return string Short human-readable name
     */
    public function get_name(): string;

    /**
     * Count what this step would remove, without removing any of it.
     *
     * Implementations of this method must not write. It is what makes a dry run trustworthy,
     * and it is the default the scheduled task takes.
     *
     * @param output_interface $output Output handler for progress
     * @return step_result What would be removed
     */
    public function report(output_interface $output): step_result;

    /**
     * Remove it.
     *
     * @param output_interface $output Output handler for progress
     * @return step_result What was removed
     */
    public function execute(output_interface $output): step_result;
}
