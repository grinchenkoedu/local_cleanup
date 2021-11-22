<?php

namespace local_cleanup\form;

use html_writer;
use moodleform;

class confirmation_form extends moodleform
{
    /**
     * @return bool
     */
    public function is_confirmed()
    {
        return (bool)$this->get_data();
    }

    /**
     * @throws \coding_exception
     */
    public function definition()
    {
        $this->_form->addElement('hidden', 'id', $this->_customdata['id']);
        $this->_form->setType('id', PARAM_INT);

        $this->_form->addElement('hidden', 'redirect', $this->_customdata['redirect']);
        $this->_form->setType('redirect', PARAM_TEXT);

        $this->_form->addElement('html', html_writer::tag('div', $this->_customdata['message']));

        if (!empty($this->_customdata['html'])) {
            $this->_form->addElement('html', html_writer::tag('div', $this->_customdata['html']));
        }

        $this->_form->addGroup($this->getButtons(), 'buttonarr', '', [' '], false);
    }

    /**
     * @return array
     */
    private function getButtons()
    {
        return [
            $this->_form->createElement('submit', 'submitbutton', get_string('ok')),
            $this->_form->createElement('cancel')
        ];
    }
}
