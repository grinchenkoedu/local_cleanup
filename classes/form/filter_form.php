<?php

namespace local_cleanup\form;

use moodleform;

class filter_form extends moodleform
{
    protected function definition()
    {
        $form = $this->_form;
        $filesize = $this->_customdata['filesize'] ?? 0;
        $name_like = $this->_customdata['name_like'] ?? '';
        $user_like = $this->_customdata['user_like'] ?? '';
        $user_deleted = $this->_customdata['user_deleted'] ?? false;
        $component = $this->_customdata['component'] ?? null;

        $form->addElement('header', 'header', get_string('filter'));
        $form->setExpanded(
            'header',
            !empty($name_like) || !empty($user_like) || !empty($component)
        );

        $form->addElement('text', 'name_like', get_string('filename', 'backup'));
        $form->setType('name_like', PARAM_TEXT);
        $form->setDefault('name_like', $name_like);

        $form->addElement('text', 'user_like', get_string('user', 'admin'));
        $form->setType('user_like', PARAM_TEXT);
        $form->setDefault('user_like', $user_like);

        $form->addElement('checkbox', 'user_deleted', 'Deleted users');
        $form->setDefault('user_deleted', $user_deleted);

        $form->addElement('select', 'component', get_string('module', 'backup'), [
            null => '-',
            'tool_recyclebin' => get_string('pluginname', 'tool_recyclebin'),
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

    private function getButtons()
    {
        return [
            $this->_form->createElement('submit', 'submitbutton', get_string('search')),
            $this->_form->createElement('cancel')
        ];
    }
}
