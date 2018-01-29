<?php
// This file is part of Lucimoo
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Import EPUB form.
 *
 * @package    booktool
 * @subpackage importepub
 * @copyright  2013-2018 Mikael Ylikoski
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* This file contains code based on mod/book/tool/importhtml/import_form.php
 * (copyright 2004-2011 Petr Skoda) from Moodle 2.4. */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . DIRECTORY_SEPARATOR . 'formslib.php');

class booktool_importepub_add_form extends moodleform {

    public function definition() {
        $mform = $this->_form;
        $data = $this->_customdata;

        $mform->addElement('header', 'generalfile', get_string('import'));
        if (method_exists($mform, 'setExpanded')) {     // Moodle 2.5
            $mform->setExpanded('generalfile');
        }

        $mform->addElement('filemanager', 'importfile',
                           get_string('epubfile', 'booktool_importepub'), null,
                           array('subdirs' => 0,
                                 'accepted_types' => array('.epub')));

        $mform->addElement('header', 'general',
                           get_string('importurls', 'booktool_importepub'));
        if (method_exists($mform, 'setExpanded')) {     // Moodle 2.5
            $mform->setExpanded('general');
        }

        $mform->addElement('textarea', 'urllist',
                           get_string('urllist', 'booktool_importepub'),
                           'wrap="virtual" rows="2" cols="50"');
        $mform->setType('urllist', PARAM_RAW);

        $mform->addElement('header', 'options',
                           get_string('optionsheader', 'resource'));
        if (method_exists($mform, 'setExpanded')) {     // Moodle 2.5
            $mform->setExpanded('options');
        }

        $mform->addElement('checkbox', 'chaptersasbooks', '',
                           get_string('chaptersasbooks',
                                      'booktool_importepub'));

        $mform->addElement('textarea', 'header',
                           get_string('addheader', 'booktool_importepub'),
                           'wrap="virtual" rows="2" cols="50"');
        $mform->setType('header', PARAM_RAW);

        $mform->addElement('textarea', 'footer',
                           get_string('addfooter', 'booktool_importepub'),
                           'wrap="virtual" rows="2" cols="50"');
        $mform->setType('footer', PARAM_RAW);

        $mform->addElement('header', 'stylesheets',
                           get_string('stylesheets', 'booktool_importepub'));
        if (method_exists($mform, 'setExpanded')) {     // Moodle 2.5
            $mform->setExpanded('stylesheets');
        }

        $mform->addElement('checkbox', 'enablestylesheets', '',
                           get_string('enablestylesheets',
                                      'booktool_importepub'));
        $mform->setDefault('enablestylesheets', 1);

        $mform->addElement('checkbox', 'preventsmallfonts', '',
                           get_string('preventsmallfonts',
                                      'booktool_importepub'));

        $mform->addElement('checkbox', 'ignorefontfamily', '',
                           get_string('ignorefontfamily',
                                      'booktool_importepub'));

        $mform->addElement('header', 'divide_options',
                           get_string('subchapters', 'booktool_importepub'));
        if (method_exists($mform, 'setExpanded')) {     // Moodle 2.5
            $mform->setExpanded('divide_options');
        }

        $radioarray = array();
        $radioarray[] = $mform->createElement('radio', 'tag', '',
                                              get_string('none',
                                                         'booktool_importepub'),
                                              '', '');
        $radioarray[] = $mform->createElement('radio', 'tag', '',
                                              '&lt;h1&gt;', 'h1', '');
        $radioarray[] = $mform->createElement('radio', 'tag', '',
                                              '&lt;h2&gt;', 'h2', '');
        $radioarray[] = $mform->createElement('radio', 'tag', '',
                                              '&lt;h3&gt;', 'h3', '');
        $radioarray[] = $mform->createElement('radio', 'tag', '',
                                              '&lt;h4&gt;', 'h4', '');
        $radioarray[] = $mform->createElement('radio', 'tag', '',
                                              '&lt;h5&gt;', 'h5', '');
        $radioarray[] = $mform->createElement('radio', 'tag', '',
                                              '&lt;h6&gt;', 'h6', '');
        $radioarray[] = $mform->createElement('radio', 'tag', '',
                                              '&lt;section&gt;', 'section', '');
        $radioarray[] = $mform->createElement('radio', 'tag', '',
                                              '&lt;div&gt;', 'div', '');
        $radioarray[] = $mform->createElement('radio', 'tag', '',
                                              '&lt;p&gt;', 'p', '');
        $mform->addGroup($radioarray, 'radioar',
                         get_string('dividetag', 'booktool_importepub'),
                         array(' '), false);

        $mform->addElement('text', 'classes',
                           get_string('divideclass', 'booktool_importepub'),
                           '');
        $mform->setType('classes', PARAM_NOTAGS);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'chapterid');
        $mform->setType('chapterid', PARAM_INT);

        $this->add_action_buttons(true, get_string('import'));

        $this->set_data($data);
    }

    public function validation($data, $files) {
        global $USER;

        if ($errors = parent::validation($data, $files)) {
            return $errors;
        }

        $usercontext = context_user::instance($USER->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft',
                                     $data['importfile'], 'id', false);
        if (count($files) > 0) {
            $file = reset($files);
            $mimetype = $file->get_mimetype();
            if ($mimetype != 'application/epub+zip' and
                $mimetype != 'application/zip' and
                $mimetype != 'document/unknown' and
                $mimetype != null) {
                $errors['importfile'] = get_string('invalidfiletype',
                                                   'error',
                                                   $file->get_filename());
                $fs->delete_area_files($usercontext->id, 'user', 'draft',
                                       $data['importfile']);
            }
        } else if (strlen($data['urllist']) > 0) {
            ;
        } else {
            $errors['importfile'] = get_string('required');
        }
        return $errors;
    }
}
