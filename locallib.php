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
 * Import/Export Microsoft Word files library.
 *
 * @package    booktool_wordimport
 * @copyright  2016 Eoin Campbell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;
// Development: turn on all debug messages and strict warnings.
define('DEBUG_WORDIMPORT', E_ALL);
// @codingStandardsIgnoreLine define('DEBUG_WORDIMPORT', 0);

require_once(dirname(__FILE__).'/lib.php');
require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->dirroot.'/mod/book/lib.php');
require_once($CFG->dirroot.'/mod/book/locallib.php');
require_once($CFG->dirroot.'/mod/book/tool/importhtml/locallib.php');

use \booktool_wordimport\wordconverter;

/**
 * Import HTML pages from a Word file
 *
 * @param string $wordfilename Word file
 * @param stdClass $book
 * @param context_module $context
 * @param bool $splitonsubheadings
 * @return void
 */
function booktool_wordimport_import_word($wordfilename, $book, $context, $splitonsubheadings) {
    global $CFG;

    // Convert the Word file content into XHTML and an array of images.
    $imagesforzipping = array();
    $word2xml = new wordconverter();
    $htmlcontent = $word2xml->import($wordfilename, $imagesforzipping);

    // Create a temporary Zip file to store the HTML and images for feeding to import function.
    $zipfilename = $CFG->tempdir . DIRECTORY_SEPARATOR . basename($wordfilename, ".tmp") . ".zip";
    $zipfile = new ZipArchive;
    if (!($zipfile->open($zipfilename, ZipArchive::CREATE))) {
        // Cannot open zip file.
        throw new moodle_exception('cannotopenzip', 'error');
    }

    // Add any images to the Zip file.
    if (count($imagesforzipping) > 0) {
        foreach ($imagesforzipping as $imagename => $imagedata) {
            $zipfile->addFromString($imagename, $imagedata);
        }
    }

    // Split the single HTML file into multiple chapters based on h1 elements.
    $h1matches = null;
    $chaptermatches = null;
    // Grab title and contents of each 'Heading 1' section, which is mapped to h3.
    $chaptermatches = preg_split('#<h3>.*</h3>#isU', $htmlcontent);
    preg_match_all('#<h3>(.*)</h3>#i', $htmlcontent, $h1matches);
    // @codingStandardsIgnoreLine debugging(__FUNCTION__ . ":" . __LINE__ . ": n chapters = " . count($chaptermatches), DEBUG_WORDIMPORT);

    // If no h3 elements are present, treat the whole file as a single chapter.
    if (count($chaptermatches) == 1) {
        $zipfile->addFromString("index.htm", $htmlcontent);
    }

    // Create a separate HTML file in the Zip file for each section of content.
    for ($i = 1; $i < count($chaptermatches); $i++) {
        // Remove any tags from heading, as it prevents proper import of the chapter title.
        $chaptitle = strip_tags($h1matches[1][$i - 1]);
        // @codingStandardsIgnoreLine debugging(__FUNCTION__ . ":" . __LINE__ . ": chaptitle = " . $chaptitle, DEBUG_WORDIMPORT);
        $chapcontent = $chaptermatches[$i];
        $chapfilename = sprintf("index%02d.htm", $i);

        // Remove the closing HTML markup from the last section.
        if ($i == (count($chaptermatches) - 1)) {
            $chapcontent = substr($chapcontent, 0, strpos($chapcontent, "</div></body>"));
        }

        if ($splitonsubheadings) {
            // Save each subsection as a separate HTML file with a '_sub.htm' suffix.
            $h2matches = null;
            $subchaptermatches = null;
            // Grab title and contents of each subsection.
            preg_match_all('#<h4>(.*)</h4>#i', $chapcontent, $h2matches);
            $subchaptermatches = preg_split('#<h4>.*</h4>#isU', $chapcontent);

            // First save the initial chapter content.
            $chapcontent = $subchaptermatches[0];
            $chapfilename = sprintf("index%02d_00.htm", $i);
            $htmlfilecontent = "<html><head><title>{$chaptitle}</title></head>" .
                "<body>{$chapcontent}</body></html>";
            $zipfile->addFromString($chapfilename, $htmlfilecontent);

            // Save each subsection to a separate file.
            for ($j = 1; $j < count($subchaptermatches); $j++) {
                $subchaptitle = strip_tags($h2matches[1][$j - 1]);
                $subchapcontent = $subchaptermatches[$j];
                $subsectionfilename = sprintf("index%02d_%02d_sub.htm", $i, $j);
                $htmlfilecontent = "<html><head><title>{$subchaptitle}</title></head>" .
                    "<body>{$subchapcontent}</body></html>";
                $zipfile->addFromString($subsectionfilename, $htmlfilecontent);
            }
        } else {
            // Save each section as a HTML file.
            $htmlfilecontent = "<html><head><title>{$chaptitle}</title></head>" .
                "<body>{$chapcontent}</body></html>";
            $zipfile->addFromString($chapfilename, $htmlfilecontent);
        }
    }
    $zipfile->close();

    // Add the Zip file to the file storage area.
    $fs = get_file_storage();
    $zipfilerecord = array(
        'contextid' => $context->id,
        'component' => 'user',
        'filearea' => 'draft',
        'itemid' => $book->revision,
        'filepath' => "/",
        'filename' => basename($zipfilename)
        );
    $zipfile = $fs->create_file_from_pathname($zipfilerecord, $zipfilename);

    // Call the standard HTML import function to really import the content.
    // Argument 2, value 2 = Each HTML file represents 1 chapter.
    toolbook_importhtml_import_chapters($zipfile, 2, $book, $context);

}


/**
 * Delete previously unzipped Word file
 *
 * @param context_module $context
 */
function booktool_wordimport_delete_files($context) {
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_book', 'wordimporttemp', 0);
}
