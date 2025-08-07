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
     */
    protected function definition() {
        $form = $this->_form;
        $filesize = $this->_customdata['filesize'] ?? 0;
        $namelike = $this->_customdata['name_like'] ?? '';
        $userlike = $this->_customdata['user_like'] ?? '';
        $userdeleted = $this->_customdata['user_deleted'] ?? false;
        $component = $this->_customdata['component'] ?? null;

        $form->addElement('header', 'header', get_string('filter'));
        $form->setExpanded(
            'header',
            !empty($namelike) || !empty($userlike) || !empty($component)
        );

        $form->addElement('text', 'name_like', get_string('filename', 'backup'));
        $form->setType('name_like', PARAM_TEXT);
        $form->setDefault('name_like', $namelike);

        $form->addElement('text', 'user_like', get_string('user', 'admin'));
        $form->setType('user_like', PARAM_TEXT);
        $form->setDefault('user_like', $userlike);

        $form->addElement('checkbox', 'user_deleted', 'Deleted users');
        $form->setDefault('user_deleted', $userdeleted);

        $form->addElement('select', 'component', get_string('module', 'backup'), [
            null => '-',
            'tool_recyclebin' => get_string('pluginname', 'tool_recyclebin'),
            'backup' => get_string('backup'),
            'user' => get_string('user', 'admin'),
        ]);
        $form->setDefault('component', $component);

        $form->addElement('select', 'filesize', '>=', [
            0 => '-',
            10 => '10 MB',
            50 => '50 MB',
            100 => '100 MB',
            200 => '200 MB',
            500 => '500 MB',
            1000 => '1 GB',
        ]);
        $form->setDefault('filesize', $filesize);

        $form->addGroup($this->getButtons(), 'buttonarr', '', [' '], false);

        $form->disable_form_change_checker();
    }

    /**
     * Get the form buttons.
     *
     * @return array Array of form elements for the buttons
     */
    private function getbuttons() {
        return [
            $this->_form->createElement('submit', 'submitbutton', get_string('search')),
            $this->_form->createElement('cancel'),
        ];
    }
}
