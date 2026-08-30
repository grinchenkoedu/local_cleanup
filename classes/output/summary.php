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

use renderable;
use renderer_base;
use templatable;

/**
 * The figures above a report.
 *
 * Deliberately a list of label and value pairs rather than one assembled sentence: the reports
 * and the dry run want the same block with different rows in it, and a template escapes every
 * value it prints, which the nested html_writer calls this replaces did not.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class summary implements renderable, templatable {
    /**
     * Label and value pairs, in the order they should appear.
     *
     * @var array[]
     */
    private $items = [];

    /**
     * Something to say underneath, if anything.
     *
     * @var string|null
     */
    private $note = null;

    /**
     * Add a figure.
     *
     * @param string $label What it is
     * @param string $value The figure itself, already formatted for reading
     * @return self This, so figures can be chained
     */
    public function add(string $label, string $value): self {
        $this->items[] = ['label' => $label, 'value' => $value];

        return $this;
    }

    /**
     * Set the line underneath the figures.
     *
     * @param string|null $note The note, or null for none
     * @return self This, so it can be chained
     */
    public function set_note(?string $note): self {
        $this->note = $note;

        return $this;
    }

    /**
     * Export for the template.
     *
     * @param renderer_base $output The renderer
     * @return array Context for local_cleanup/summary
     */
    public function export_for_template(renderer_base $output): array {
        return [
            'items' => $this->items,
            'hasnote' => $this->note !== null && $this->note !== '',
            'note' => (string)$this->note,
        ];
    }
}
