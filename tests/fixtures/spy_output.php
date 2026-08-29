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
 * Output handler that records messages instead of printing them.
 *
 * Clean-up steps write progress through output_interface. In a test that output would
 * otherwise reach mtrace and be reported as unexpected output, so it is captured here and
 * made available for assertions.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class spy_output implements output_interface {
    /**
     * Everything written, in order.
     *
     * @var string[]
     */
    private $messages = [];

    /**
     * Record a message written without a line break.
     *
     * @param string $message The message to write
     * @return void
     */
    public function write(string $message) {
        $this->messages[] = $message;
    }

    /**
     * Record a message written with a line break.
     *
     * @param string $message The message to write
     * @return void
     */
    public function write_line(string $message) {
        $this->messages[] = $message;
    }

    /**
     * Get everything that was written.
     *
     * @return string[] Messages in the order they were written
     */
    public function get_messages(): array {
        return $this->messages;
    }

    /**
     * Check whether any message contains the given text.
     *
     * @param string $needle Text to look for
     * @return bool True when at least one message contains it
     */
    public function contains(string $needle): bool {
        foreach ($this->messages as $message) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
