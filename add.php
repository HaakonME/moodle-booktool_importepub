<?php
// This file is part of Moodle - http://moodle.org/
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
 * Import Microsoft Word file add book function.
 *
 * @package    booktool
 * @subpackage wordimport
 * @copyright  2015 Eoin Campbell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* This file contains code based on mod/book/tool/print/index.php
 * and mod/book/tool/importhtml/index.php
 * (copyright 2004-2011 Petr Skoda) from Moodle 2.4. */

require(dirname(__FILE__).'/../../../../config.php');

$id        = required_param('id', PARAM_INT);           // Course Module ID.

// Security checks.
$cm = get_coursemodule_from_id('book', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

require_course_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/book:addinstance', $context);
require_capability('mod/book:edit', $context);
require_capability('booktool/wordimport:import', $context);

/*
if (!(property_exists($USER, 'editing') and $USER->editing)) {
}
*/

$PAGE->set_url('/mod/book/tool/wordimport/add.php', array('id' => $id));

require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'locallib.php');
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'add_form.php');

$PAGE->set_title(get_string('wordimport', 'booktool_wordimport'));
$PAGE->set_heading($course->fullname);

$mform = new booktool_wordimport_add_form(null, array('id' => $id));

if ($mform->is_cancelled()) {
    // FIXME.
    redirect($CFG->wwwroot."/mod/book/view.php?id=$cm->id");
} else if ($data = $mform->get_data()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('wordimport', 'booktool_wordimport'));

    $fs = get_file_storage();
    $usercontext = context_user::instance($USER->id);
    $section = $DB->get_field('course_sections', 'section',
                              array('id' => $cm->section));
    if (property_exists($data, 'submitfilebutton')) {
        $draftid = file_get_submitted_draft_itemid('importfile');
        if (!$files = $fs->get_area_files($usercontext->id, 'user', 'draft',
                                          $draftid, 'id DESC', false)) {
            redirect($PAGE->url);
        }
        foreach ($files as $file) {
            if (property_exists($data, 'chaptersasbooks')) {
                toolbook_wordimport_add_word_chapters($file, $course, $section);
            } else {
                toolbook_wordimport_add_word($file, $course, $section);
            }
        }
    }

    echo $OUTPUT->continue_button(new moodle_url('/mod/book/view.php',
                                                 array('id' => $id)));
    echo $OUTPUT->footer();
    die;
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('wordimport', 'booktool_wordimport'));

    $mform->display();

    echo $OUTPUT->footer();
}
