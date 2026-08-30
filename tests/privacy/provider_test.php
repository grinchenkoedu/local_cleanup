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

namespace local_cleanup\privacy;

use advanced_testcase;
use core_privacy\local\metadata\null_provider;

/**
 * Tests for the privacy provider.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_cleanup\privacy\provider
 */
final class provider_test extends advanced_testcase {
    /**
     * The plugin declares that it stores nothing.
     *
     * @return void
     */
    public function test_the_plugin_stores_no_personal_data(): void {
        $this->assertInstanceOf(null_provider::class, new provider());
    }

    /**
     * The reason names a string that exists, so the privacy registry has something to show.
     *
     * A null provider whose reason does not resolve renders as a missing string on the
     * site's data registry page, which is the one place this class is ever read.
     *
     * @return void
     */
    public function test_the_reason_resolves_to_a_string(): void {
        $this->resetAfterTest();

        $reason = provider::get_reason();

        $this->assertTrue(
            get_string_manager()->string_exists($reason, 'local_cleanup'),
            'The reason must name a string in the plugin language file.'
        );
        $this->assertNotEmpty(get_string($reason, 'local_cleanup'));
    }
}
