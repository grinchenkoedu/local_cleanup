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

namespace local_cleanup\form;

use advanced_testcase;
use cache;
use context_system;
use local_cleanup\finder;

/**
 * Tests for the files report filter.
 *
 * @package    local_cleanup
 * @copyright  2026 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_cleanup\form\filter_form
 * @covers     \local_cleanup\finder::get_components
 */
final class filter_form_test extends advanced_testcase {
    /**
     * The component list comes from the components that actually own files.
     *
     * It used to be four hardcoded entries, so a site could not filter by anything else it
     * happened to store.
     *
     * @return void
     */
    public function test_the_component_list_reflects_the_site(): void {
        global $DB;

        $this->resetAfterTest();
        $this->purge_component_cache();

        $this->create_file('assignsubmission_file');
        $this->create_file('mod_folder');

        $components = (new finder($DB))->get_components();

        $this->assertContains('assignsubmission_file', $components);
        $this->assertContains('mod_folder', $components);
    }

    /**
     * The answer is cached, because it is a DISTINCT over the largest table on the site.
     *
     * @return void
     */
    public function test_the_component_list_is_cached(): void {
        global $DB;

        $this->resetAfterTest();
        $this->purge_component_cache();

        $finder = new finder($DB);
        $this->create_file('mod_folder');

        $first = $finder->get_components();
        $this->assertContains('mod_folder', $first);

        // A component appearing after the answer was cached is not picked up until it expires.
        $this->create_file('mod_page');

        $this->assertSame($first, $finder->get_components());
        $this->purge_component_cache();
        $this->assertContains('mod_page', $finder->get_components());
    }

    /**
     * A component whose plugin is gone still labels itself rather than failing.
     *
     * Files outlive the plugin that created them, so get_string('pluginname', ...) cannot be
     * assumed to resolve.
     *
     * @return void
     */
    public function test_an_uninstalled_component_still_appears(): void {
        global $DB;

        $this->resetAfterTest();
        $this->purge_component_cache();

        $this->create_file('mod_longgone');

        $form = new filter_form(null, [
            'components' => (new finder($DB))->get_components(),
        ]);

        $this->assertStringContainsString('mod_longgone', $form->render());
    }

    /**
     * The deleted-user filter can be turned off again once it has been turned on.
     *
     * It was a plain checkbox, which submits nothing when cleared, so the value persisted and
     * the filter could never be removed. That is what advcheckbox fixes, and it is only
     * visible through a round trip.
     *
     * @return void
     */
    public function test_the_deleted_user_filter_can_be_cleared(): void {
        $this->resetAfterTest();

        $data = $this->submit(['user_deleted' => 0]);

        $this->assertNotNull($data, 'The form should accept the submission.');
        $this->assertSame(0, (int)$data->user_deleted, 'Clearing the box must reach the filter.');
    }

    /**
     * And it still arrives when it is turned on.
     *
     * @return void
     */
    public function test_the_deleted_user_filter_can_be_set(): void {
        $this->resetAfterTest();

        $data = $this->submit(['user_deleted' => 1]);

        $this->assertSame(1, (int)$data->user_deleted);
    }

    /**
     * The chosen size reaches the caller.
     *
     * Note it arrives as a string, not an int, despite setType(PARAM_INT). A select exports
     * through HTML_QuickForm_select::exportValue(), which returns the key from its own option
     * list rather than the cleaned submitted value, so setType cleans _submitValues without
     * changing what get_data() hands back. files.php re-reads the value with
     * optional_param(..., PARAM_INT), which is where the typing that matters happens.
     *
     * @return void
     */
    public function test_the_chosen_size_reaches_the_caller(): void {
        $this->resetAfterTest();

        $data = $this->submit(['filesize' => 100]);

        $this->assertEquals(100, $data->filesize);
    }

    /**
     * Submit the filter and return what it hands back.
     *
     * @param array $overrides Values to submit in place of the defaults
     * @return \stdClass|null The submitted data
     */
    private function submit(array $overrides) {
        filter_form::mock_submit($overrides + [
            'name_like' => '',
            'user_like' => '',
            'user_deleted' => 0,
            'component' => '',
            'filesize' => 50,
        ]);

        return (new filter_form(null, ['components' => []]))->get_data();
    }

    /**
     * Clear the cached component list so a test sees its own fixtures.
     *
     * @return void
     */
    private function purge_component_cache(): void {
        cache::make('local_cleanup', 'componentlist')->purge();
    }

    /**
     * Create a file owned by the given component.
     *
     * @param string $component Owning component
     * @return void
     */
    private function create_file(string $component): void {
        get_file_storage()->create_file_from_string([
            'contextid' => context_system::instance()->id,
            'component' => $component,
            'filearea' => 'test',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $component . '.txt',
        ], 'content ' . random_string(16));
    }
}
