<?php

namespace local_cleanup\form;

use moodleform;

class filter_form extends moodleform
{
    protected function definition()
    {
        $_form = $this->_form;
        $name_like = $this->_customdata['name_like'] ?? '';
        $user_like = $this->_customdata['user_like'] ?? '';

        $_form->addElement('header', 'header', get_string('filter'));
        $_form->setExpanded('header', !empty($name_like) || !empty($user_like));

        $_form->addElement('text', 'name_like', get_string('filename', 'backup'));
        $_form->setType('name_like', PARAM_TEXT);
        $_form->setDefault('name_like', $name_like);

        $_form->addElement('text', 'user_like', get_string('user', 'admin'));
        $_form->setType('user_like', PARAM_TEXT);
        $_form->setDefault('user_like', $user_like);

        $_form->addGroup($this->getButtons(), 'buttonarr', '', [' '], false);

        $_form->disable_form_change_checker();
    }

    /**
     * @return array
     */
    private function getButtons()
    {
        return [
            $this->_form->createElement('submit', 'submitbutton', get_string('search')),
            $this->_form->createElement('cancel')
        ];
    }
}
