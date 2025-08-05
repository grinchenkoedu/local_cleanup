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

namespace local_cleanup\output;

/**
 * Interface for output handlers.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface OutputInterface {

    /**
     * Write a message without a line break.
     *
     * @param string $message The message to write
     * @return void
     */
    public function write(string $message);

    /**
     * Write a message with a line break.
     *
     * @param string $message The message to write
     * @return void
     */
    public function writeline(string $message);
}
