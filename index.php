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
 * Import EPUB import chapter function.
 *
 * @package    booktool
 * @subpackage importepub
 * @copyright  2013-2018 Mikael Ylikoski
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* This file contains code based on mod/book/tool/print/index.php
 * and mod/book/tool/importhtml/index.php
 * (copyright 2004-2011 Petr Skoda) from Moodle 2.4. */

require(dirname(__FILE__).'/../../../../config.php');

$id        = required_param('id', PARAM_INT);           // Course Module ID
$chapterid = optional_param('chapterid', 0, PARAM_INT); // Chapter ID

// =========================================================================
// security checks

$cm = get_coursemodule_from_id('book', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$book = $DB->get_record('book', array('id' => $cm->instance), '*', MUST_EXIST);

require_course_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/book:edit', $context);
require_capability('booktool/importepub:import', $context);

// =========================================================================

/*
if (!(property_exists($USER, 'editing') and $USER->editing)) {
}
*/

if ($chapterid) {
    if (!$chapter = $DB->get_record('book_chapters', array('id' => $chapterid, 'bookid' => $book->id))) {
        $chapterid = 0;
    }
} else {
    $chapter = false;
}

$PAGE->set_url('/mod/book/tool/importepub/index.php',
               array('id' => $id, 'chapterid' => $chapterid));

require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'locallib.php');
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'import_form.php');

$PAGE->set_title($book->name);
$PAGE->set_heading($course->fullname);

$mform = new booktool_importepub_form(null, array('id' => $id,
                                                  'chapterid' => $chapterid));

if ($mform->is_cancelled()) {
    if (empty($chapter->id)) {
        redirect($CFG->wwwroot . "/mod/book/view.php?id=$cm->id");
    } else {
        redirect($CFG->wwwroot . "/mod/book/view.php?id=$cm->id&chapterid=$chapter->id");
    }
} else if ($data = $mform->get_data()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('importchapters', 'booktool_importepub'));

    $settings = new stdClass();
    $settings->enablestyles = property_exists($data, 'enablestylesheets');
    $settings->preventsmallfonts = property_exists($data, 'preventsmallfonts');
    $settings->ignorefontfamily = property_exists($data, 'ignorefontfamily');
    $settings->tag = $data->tag;
    if (strlen($data->classes) > 0) {
        $settings->classes = preg_split('/\s+/', $data->classes,
                                        -1, PREG_SPLIT_NO_EMPTY);
    } else {
        $settings->classes = array();
    }
    $settings->header = $data->header;
    $settings->footer = $data->footer;

    $fs = get_file_storage();
    $draftid = file_get_submitted_draft_itemid('importfile');
    $usercontext = context_user::instance($USER->id);
    if (!$files = $fs->get_area_files($usercontext->id, 'user', 'draft',
                                      $draftid, 'id DESC', false)) {
        redirect($PAGE->url);
    }
    $file = reset($files);

    toolbook_importepub_import_epub($file, $book, $context, $settings);

    echo $OUTPUT->continue_button(new moodle_url('/mod/book/view.php',
                                                 array('id' => $id)));
    echo $OUTPUT->footer();
    die;
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('importchapters', 'booktool_importepub'));

    $mform->display();

    echo $OUTPUT->footer();
}
