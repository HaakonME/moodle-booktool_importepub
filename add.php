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
 * Import EPUB add book function.
 *
 * @package    booktool
 * @subpackage importepub
 * @copyright  2013-2014 Mikael Ylikoski
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* This file contains code based on mod/book/tool/print/index.php
 * and mod/book/tool/importhtml/index.php
 * (copyright 2004-2011 Petr Skoda) from Moodle 2.4. */

require(dirname(__FILE__).'/../../../../config.php');

$id        = required_param('id', PARAM_INT);           // Course Module ID

// =========================================================================
// security checks

$cm = get_coursemodule_from_id('book', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

require_course_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/book:addinstance', $context);
require_capability('mod/book:edit', $context);
require_capability('booktool/importepub:import', $context);

// =========================================================================

/*
if (!(property_exists($USER, 'editing') and $USER->editing)) {
}
*/

$PAGE->set_url('/mod/book/tool/importepub/add.php', array('id' => $id));

require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'locallib.php');
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'add_form.php');

$PAGE->set_title(get_string('importepub', 'booktool_importepub'));
$PAGE->set_heading($course->fullname);

$mform = new booktool_importepub_add_form(null, array('id' => $id));

if ($mform->is_cancelled()) {
    // FIXME
    redirect($CFG->wwwroot."/mod/book/view.php?id=$cm->id");
} else if ($data = $mform->get_data()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('importepub', 'booktool_importepub'));

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
        $enablestylesheets = property_exists($data, 'enablestylesheets');
        foreach ($files as $file) {
            if (property_exists($data, 'chaptersasbooks')) {
                toolbook_importepub_add_epub_chapters($file, $course, $section,
                                                      $enablestylesheets);
            } else {
                toolbook_importepub_add_epub($file, $course, $section,
                                             $enablestylesheets);
            }
        }
    } else {
        require_once($CFG->libdir . '/filelib.php');

        foreach (preg_split("/\s+/", $data->urllist) as $line) {
            if (!$line) {
                continue;
            }
            $fdata = download_file_content($line);
            if (!$fdata) {
                echo $OUTPUT->notification('Could not import: ' . htmlentities($line, ENT_COMPAT, 'UTF-8'), 'notifyproblem');
                continue;
            }

            $fileinfo = array('contextid' => $usercontext->id,
                              'component' => 'importepub',
                              'filearea' => 'draft',
                              'itemid' => 0,
                              'filepath' => '/',
                              'filename' => 'luci.epub');
            $file = $fs->get_file($fileinfo['contextid'],
                                  $fileinfo['component'],
                                  $fileinfo['filearea'], $fileinfo['itemid'],
                                  $fileinfo['filepath'], $fileinfo['filename']);
            if ($file) {
                $file->delete();
            }
            $file = $fs->create_file_from_string($fileinfo, $fdata);
            unset($fdata);
            $enablestylesheets = property_exists($data, 'enablestylesheets');
            if (property_exists($data, 'chaptersasbooks')) {
                toolbook_importepub_add_epub_chapters($file, $course, $section,
                                                      $enablestylesheets);
            } else {
                toolbook_importepub_add_epub($file, $course, $section,
                                             $enablestylesheets);
            }
            if ($file) {
                $file->delete();
            }
        }
    }

    echo $OUTPUT->continue_button(new moodle_url('/mod/book/view.php',
                                                 array('id' => $id)));
    echo $OUTPUT->footer();
    die;
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('importepub', 'booktool_importepub'));

    $mform->display();

    echo $OUTPUT->footer();
}
