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

/**
 * What a clean-up step did, or what it would do.
 *
 * The same object serves a dry run and a real one, which is the point: an operator can see the
 * numbers before agreeing to them, and the reporting path cannot drift from the deleting path
 * because both build this.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_result {
    /**
     * Records affected.
     *
     * @var int
     */
    private $records = 0;

    /**
     * Bytes reclaimed, where the step knows. Row deletions report nothing here.
     *
     * @var int
     */
    private $bytes = 0;

    /**
     * Anything the operator should read, such as a table that was skipped.
     *
     * @var string[]
     */
    private $notes = [];

    /**
     * Add to the tally.
     *
     * @param int $records Records affected
     * @param int $bytes Bytes reclaimed, if known
     * @return void
     */
    public function add(int $records, int $bytes = 0): void {
        $this->records += $records;
        $this->bytes += $bytes;
    }

    /**
     * Record something worth saying that is not a number.
     *
     * @param string $note The note
     * @return void
     */
    public function note(string $note): void {
        $this->notes[] = $note;
    }

    /**
     * Fold another result into this one.
     *
     * @param step_result $other Result to absorb
     * @return void
     */
    public function merge(step_result $other): void {
        $this->records += $other->get_records();
        $this->bytes += $other->get_bytes();
        $this->notes = array_merge($this->notes, $other->get_notes());
    }

    /**
     * How many records were, or would be, affected.
     *
     * @return int Record count
     */
    public function get_records(): int {
        return $this->records;
    }

    /**
     * How many bytes were, or would be, reclaimed.
     *
     * @return int Bytes
     */
    public function get_bytes(): int {
        return $this->bytes;
    }

    /**
     * Anything the operator should read.
     *
     * @return string[] Notes
     */
    public function get_notes(): array {
        return $this->notes;
    }

    /**
     * Whether this step found anything at all.
     *
     * @return bool True when nothing was found
     */
    public function is_empty(): bool {
        return $this->records === 0;
    }

    /**
     * One line summarising the tally.
     *
     * @return string Summary
     */
    public function summarise(): string {
        if ($this->is_empty()) {
            return 'nothing found';
        }

        if ($this->bytes > 0) {
            return sprintf('%d record(s), %s', $this->records, display_size($this->bytes));
        }

        return sprintf('%d record(s)', $this->records);
    }
}
