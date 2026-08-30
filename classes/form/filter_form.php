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

use moodleform;

/**
 * Filter form for files search.
 *
 * @package    local_cleanup
 * @copyright  2024 Grinchenko University
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filter_form extends moodleform {
    /**
     * Define the form elements.
     *
     * @return void
     */
    protected function definition() {
        $form = $this->_form;
        $filesize = $this->_customdata['filesize'] ?? 0;
        $namelike = $this->_customdata['name_like'] ?? '';
        $userlike = $this->_customdata['user_like'] ?? '';
        $userdeleted = $this->_customdata['user_deleted'] ?? 0;
        $component = $this->_customdata['component'] ?? '';
        $components = $this->_customdata['components'] ?? [];

        $form->addElement('header', 'header', get_string('filter'));
        $form->setExpanded(
            'header',
            !empty($namelike) || !empty($userlike) || !empty($component) || !empty($userdeleted)
        );

        $form->addElement('text', 'name_like', get_string('filename', 'backup'));
        $form->setType('name_like', PARAM_TEXT);
        $form->setDefault('name_like', $namelike);

        $form->addElement('text', 'user_like', get_string('user', 'admin'));
        $form->setType('user_like', PARAM_TEXT);
        $form->setDefault('user_like', $userlike);

        // advcheckbox rather than checkbox: a plain checkbox submits nothing when cleared, so
        // once ticked the filter could never be turned off again.
        $form->addElement('advcheckbox', 'user_deleted', get_string('deletedusers', 'local_cleanup'));
        $form->setType('user_deleted', PARAM_BOOL);
        $form->setDefault('user_deleted', $userdeleted);

        $form->addElement(
            'select',
            'component',
            get_string('component', 'cache'),
            $this->get_component_options($components)
        );
        $form->setType('component', PARAM_COMPONENT);
        $form->setDefault('component', $component);

        $form->addElement('select', 'filesize', get_string('atleastsize', 'local_cleanup'), [
            0 => get_string('any', 'local_cleanup'),
            10 => display_size(10 * 1024 * 1024),
            50 => display_size(50 * 1024 * 1024),
            100 => display_size(100 * 1024 * 1024),
            200 => display_size(200 * 1024 * 1024),
            500 => display_size(500 * 1024 * 1024),
            1000 => display_size(1000 * 1024 * 1024),
        ]);
        $form->setType('filesize', PARAM_INT);
        $form->setDefault('filesize', $filesize);

        $form->addGroup($this->get_buttons(), 'buttonarr', '', [' '], false);

        $form->disable_form_change_checker();
    }

    /**
     * Build the component menu from the components that actually own files.
     *
     * The list used to be four hardcoded entries, which meant a site could not filter by
     * anything else it happened to store.
     *
     * @param string[] $components Component names present in the files table
     * @return array Menu options, keyed by component name
     */
    private function get_component_options(array $components): array {
        $options = ['' => get_string('any', 'local_cleanup')];

        foreach ($components as $name) {
            // A component whose plugin has since been uninstalled still owns rows in {files},
            // and get_string() would fail for it, so fall back to the raw frankenstyle name.
            $options[$name] = get_string_manager()->string_exists('pluginname', $name)
                ? get_string('pluginname', $name)
                : $name;
        }

        return $options;
    }

    /**
     * Get the form buttons.
     *
     * @return array Array of form elements for the buttons
     */
    private function get_buttons() {
        return [
            $this->_form->createElement('submit', 'submitbutton', get_string('search')),
            $this->_form->createElement('cancel'),
        ];
    }
}
