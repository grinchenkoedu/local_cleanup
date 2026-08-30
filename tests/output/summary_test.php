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

use advanced_testcase;

/**
 * Tests for the report summary block.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_cleanup\output\summary
 * @covers     \local_cleanup\output\renderer
 */
final class summary_test extends advanced_testcase {
    /**
     * Figures come out in the order they went in.
     *
     * @return void
     */
    public function test_figures_keep_their_order(): void {
        global $PAGE;

        $this->resetAfterTest();

        $context = (new summary())
            ->add('Files found', '2,462')
            ->add('Total size', '1.4 GB')
            ->export_for_template($PAGE->get_renderer('local_cleanup'));

        $this->assertSame(
            [
                ['label' => 'Files found', 'value' => '2,462'],
                ['label' => 'Total size', 'value' => '1.4 GB'],
            ],
            $context['items']
        );
    }

    /**
     * The note is optional, and its absence is signalled rather than left to the template.
     *
     * @return void
     */
    public function test_the_note_is_optional(): void {
        global $PAGE;

        $this->resetAfterTest();

        $renderer = $PAGE->get_renderer('local_cleanup');

        $without = (new summary())->export_for_template($renderer);
        $this->assertFalse($without['hasnote']);

        $with = (new summary())->set_note('Next clean-up: tomorrow')->export_for_template($renderer);
        $this->assertTrue($with['hasnote']);
        $this->assertSame('Next clean-up: tomorrow', $with['note']);
    }

    /**
     * The template escapes what it prints.
     *
     * The figures are counts and sizes today, but the block is meant to be reused, and the
     * nested html_writer calls it replaces escaped nothing at all.
     *
     * @return void
     */
    public function test_the_rendered_block_escapes_its_values(): void {
        global $PAGE;

        $this->resetAfterTest();

        $html = $PAGE->get_renderer('local_cleanup')->render(
            (new summary())->add('<b>Label</b>', '<script>alert(1)</script>')
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>Label</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
