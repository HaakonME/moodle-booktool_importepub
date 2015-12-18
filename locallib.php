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
 * Import EPUB library.
 *
 * @package    booktool_wordimport
 * @copyright  2015 Eoin Campbell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* This file contains code based on mod/book/tool/importhtml/locallib.php
 * (copyright 2011 Petr Skoda) from Moodle 2.4. */

defined('MOODLE_INTERNAL') || die;
define('DEBUG_WORDIMPORT', DEBUG_DEVELOPER);

require_once(dirname(__FILE__).'/lib.php');
require_once(dirname(__FILE__).'/xslemulatexslt.inc');
require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->dirroot.'/mod/book/lib.php');
require_once($CFG->dirroot.'/mod/book/locallib.php');
require_once($CFG->dirroot.'/mod/book/tool/importhtml/locallib.php');

if (!function_exists('create_module')) {        // Moodle <= 2.4.
    function create_module($data) {
        return null;
    }
}

/**
 * Update title of book module
 *
 * @param object $data
 * @param string $title
 */
function toolbook_wordimport_update_book_title($data, $title) {
    $data->name = substr($title, 0, 250);
    $data->intro = '<p>' . htmlentities($title, ENT_COMPAT, 'UTF-8') . '</p>';
    $data->introformat = 1;
    book_update_instance($data, null);
}

/**
 * Import HTML pages from a Word file
 *
 * @param stored_file $package Word file
 * @param stdClass $book
 * @param context_module $context
 * @param bool $splitonsubheadings
 * @param bool $verbose
 */
function toolbook_wordimport_import_word($package, $book, $context, $splitonsubheadings) {
    global $OUTPUT, $USER;

    if (!$tmpfilename = $package->copy_content_to_temp()) {
        // Cannot save file.
        throw new moodle_exception(get_string('errorcreatingfile', 'error', $package->get_filename()));
    }
    // Process the Word file into a HTML file and images.
    $imagesforzipping = array();
    $htmlcontent = toolbook_wordimport_convert_to_xhtml($tmpfilename, $splitonsubheadings, $imagesforzipping);

    // Create a temporary Zip file to store the HTML and images for feeding to import function.
    $zipfilename = dirname($tmpfilename) . DIRECTORY_SEPARATOR . basename($tmpfilename, ".tmp") . ".zip";
    debugging(__FUNCTION__ . ":" . __LINE__ . ": HTML Zip file: {$zipfilename}, Word file: {$tmpfilename}", DEBUG_WORDIMPORT);
    $zipfile = new ZipArchive;
    if (!($zipfile->open($zipfilename, ZipArchive::CREATE))) {
        // Cannot open zip file.
        throw new moodle_exception('cannotopenzip', 'error');
    }

    // Add any images to the Zip file.
    if (count($imagesforzipping) > 0) {
        foreach ($imagesforzipping as $imagename => $imagedata) {
            debugging(__FUNCTION__ . ":" . __LINE__ . ": image: {$imagename}", DEBUG_WORDIMPORT);
            $zipfile->addFromString($imagename, $imagedata);
        }
    }

    // Split the single HTML file into multiple chapters based on h1 elements.
    $sectionmatches = null;
    $foundsectionmatches = preg_match('~(.+)<h1[^>]*?>~is', $htmlcontent, $sectionmatches);
    $nummatches = count($sectionmatches);
    debugging(__FUNCTION__ . ":" . __LINE__ . ": found: {$foundsectionmatches}; num = {$nummatches}", DEBUG_WORDIMPORT);
    if ($foundsectionmatches and $foundsectionmatches != 0) {
        // Get the 1st chunk of HTML before the 1st h1 element.
        preg_match('~(.+)<h1[^>]*?>~is', $htmlcontent, $startmatches);
        $chapfilename = "chap" . sprintf("%04d", 0) . ".htm";
        $zipfile->addFromString($chapfilename, $startmatches[1]);

        // Create a separate HTML file in the Zip file for each section of content.
        for ($i = 0; $i < $nummatches; $i++) {
            // Assign a filename and save the heading.
            $chapfilename = "chap" . sprintf("%04d", $i) . ".htm";

            // Get the heading text and create a HTML wrapper around the content, adding a title element.
            $sectioncontent = $sectionmatches[$i];
            preg_match('~<h1[^>]*>(.+)</h1>~is', $sectioncontent, $h1title);
            $htmlfilecontent = "<html><head><title>" . substr($h1title[1], 0, 100) .
                "</title></head><body>" . $sectioncontent . "</body></html>";
            $zipfile->addFromString($chapfilename, $htmlfilecontent);

            debugging(__FUNCTION__ . ":" . __LINE__ . ": h1 ({$chapfilename}) = \"" .
                substr($h1title[1], 0, 100) . "\"", DEBUG_WORDIMPORT);
        }
    } else {
        // No headings, so just add 1 HTML file to the Zip file.
        $zipfile->addFromString("index.htm", $htmlcontent);
    }
    $zipfile->close();

    $fs = get_file_storage();
    // Prepare filerecord array for creating each new image file.
    $fileinfo = array(
        'contextid' => $context->id,
        'component' => 'user',
        'filearea' => 'draft',
        'userid' => $USER->id,
        'itemid' => $package->get_itemid(),
        'filepath' => '/',
        'filename' => ''
        );

    $fileinfo['filename'] = basename($zipfilename);
    $fs->create_file_from_pathname($fileinfo, $zipfilename);
    // Delete the uploaded Word file.
    // @codingStandardsIgnoreLine toolbook_wordimport_delete_files($context);
    echo $OUTPUT->notification(get_string('importing', 'booktool_importhtml'), 'notifysuccess');

    // Use the default importhtml code to import the converted Word file.
    return toolbook_importhtml_import_chapters($package, 2, $book, $context);
}


/**
 * Delete previously unzipped Word file
 *
 * @param context_module $context
 */
function toolbook_wordimport_delete_files($context) {
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_book', 'wordimporttemp', 0);
}


/**
 * Extract the WordProcessingML XML files from the .docx file, and use a sequence of XSLT
 * steps to convert it into XHTML files
 *
 * @param string $filename Word file
 * @param bool $splitonsubheadings split file by 'Heading 2' style into separate HTML chunks
 * @param array $imagesforzipping array to store embedded image files
 * @return array XHTML content extracted from Word file and split into files
 */
function toolbook_wordimport_convert_to_xhtml($filename, $splitonsubheadings, &$imagesforzipping) {
    global $CFG;

    $word2xmlstylesheet1 = __DIR__ . "/wordml2xhtmlpass1.xsl"; // Convert WordML into basic XHTML.
    $word2xmlstylesheet2 = __DIR__ . "/wordml2xhtmlpass2.xsl"; // Refine basic XHTML into Word-compatible XHTML.

    debugging(__FUNCTION__ . ":" . __LINE__ . ": filename = \"{$filename}\"", DEBUG_WORDIMPORT);
    // Check that we can unzip the Word .docx file into its component files.
    $zipres = zip_open($filename);
    if (!is_resource($zipres)) {
        // Cannot unzip file.
        toolbook_wordimport_debug_unlink($filename);
        throw new moodle_exception('cannotunzipfile', 'error');
    }

    // Check that XSLT is installed.
    if (!class_exists('XSLTProcessor') || !function_exists('xslt_create')) {
        // PHP extension 'xsl' is required for this action.
        throw new moodle_exception(get_string('extensionrequired', 'tool_xmldb', 'xsl'));
    }

    // Give XSLT as much memory as possible, to enable larger Word files to be imported.
    raise_memory_limit(MEMORY_HUGE);

    if (!file_exists($word2xmlstylesheet1)) {
        // XSLT stylesheet to transform WordML into XHTML is missing.
        throw new moodle_exception('filemissing', 'moodle', $word2xmlstylesheet1);
    }

    // Set common parameters for all XSLT transformations.
    $parameters = array (
        'moodle_language' => current_language(),
        'moodle_textdirection' => (right_to_left()) ? 'rtl' : 'ltr',
        'heading1stylelevel' => '1',
        'pluginname' => 'toolbook_wordimport', // Include plugin name to control image data handling inside XSLT.
        'debug_flag' => DEBUG_WORDIMPORT
    );

    // Pre-XSLT preparation: merge the WordML and image content from the .docx Word file into one large XML file.
    // Initialise an XML string to use as a wrapper around all the XML files.
    $xmldeclaration = '<?xml version="1.0" encoding="UTF-8"?>';
    $wordmldata = $xmldeclaration . "\n<pass1Container>\n";

    $zipentry = zip_read($zipres);
    while ($zipentry) {
        if (!zip_entry_open($zipres, $zipentry, "r")) {
            // Can't read the XML file from the Word .docx file.
            zip_close($zipres);
            throw new moodle_exception('errorunzippingfiles', 'error');
        }

        $zefilename = zip_entry_name($zipentry);
        $zefilesize = zip_entry_filesize($zipentry);

        // Insert internal images into the Zip file.
        if (strpos($zefilename, "media")) {
            // @codingStandardsIgnoreLine $imageformat = substr($zefilename, strrpos($zefilename, ".") + 1);
            $imagedata = zip_entry_read($zipentry, $zefilesize);
            $imagename = basename($zefilename);
            $imagesuffix = strtolower(substr(strrchr($zefilename, "."), 1));
            // GIF, PNG, JPG and JPEG handled OK, but bmp and other non-Internet formats are not.
            if ($imagesuffix == 'gif' or $imagesuffix == 'png' or $imagesuffix == 'jpg' or $imagesuffix == 'jpeg') {
                $imagesforzipping[$imagename] = $imagedata;
                debugging(__FUNCTION__ . ":" . __LINE__ . ": added \"{$imagename}\" to Zip file", DEBUG_WORDIMPORT);
            } else {
                debugging(__FUNCTION__ . ":" . __LINE__ . ": ignore unsupported media file $zefilename" .
                    " = $imagename, imagesuffix = $imagesuffix", DEBUG_WORDIMPORT);
            }
        } else {
            // Look for required XML files, read and wrap it, remove the XML declaration, and add it to the XML string.
            // Read and wrap XML files, remove the XML declaration, and add them to the XML string.
            $xmlfiledata = preg_replace('/<\?xml version="1.0" ([^>]*)>/', "", zip_entry_read($zipentry, $zefilesize));
            switch ($zefilename) {
                case "word/document.xml":
                    $wordmldata .= "<wordmlContainer>" . $xmlfiledata . "</wordmlContainer>\n";
                    break;
                case "docProps/core.xml":
                    $wordmldata .= "<dublinCore>" . $xmlfiledata . "</dublinCore>\n";
                    break;
                case "docProps/custom.xml":
                    $wordmldata .= "<customProps>" . $xmlfiledata . "</customProps>\n";
                    break;
                case "word/styles.xml":
                    $wordmldata .= "<styleMap>" . $xmlfiledata . "</styleMap>\n";
                    break;
                case "word/_rels/document.xml.rels":
                    $wordmldata .= "<documentLinks>" . $xmlfiledata . "</documentLinks>\n";
                    break;
                case "word/footnotes.xml":
                    $wordmldata .= "<footnotesContainer>" . $xmlfiledata . "</footnotesContainer>\n";
                    break;
                case "word/_rels/footnotes.xml.rels":
                    $wordmldata .= "<footnoteLinks>" . $xmlfiledata . "</footnoteLinks>\n";
                    break;
                /* @codingStandardsIgnoreStart
                case "word/_rels/settings.xml.rels":
                    $wordmldata .= "<settingsLinks>" . $xmlfiledata . "</settingsLinks>\n";
                    break;
                    @codingStandardsIgnoreEnd
                */
                default:
                    // @codingStandardsIgnoreLine debugging(__FUNCTION__ . ":" . __LINE__ . ": Ignore $zefilename", DEBUG_WORDIMPORT);
            }
        }
        // Get the next file in the Zip package.
        $zipentry = zip_read($zipres);
    }  // End while loop.
    zip_close($zipres);

    // Close the merged XML file.
    $wordmldata .= "</pass1Container>";

    // Pass 1 - convert WordML into linear XHTML.
    // Create a temporary file to store the merged WordML XML content to transform.
    $tempwordmlfilename = $CFG->dataroot . '/temp/' . basename($filename, ".tmp") . ".wml";
    if (($nbytes = file_put_contents($tempwordmlfilename, $wordmldata)) == 0) {
        // Cannot save the file.
        throw new moodle_exception('cannotsavefile', 'error', $tempwordmlfilename);
    }

    $xsltproc = xslt_create();
    if (!($xsltoutput = xslt_process($xsltproc, $tempwordmlfilename, $word2xmlstylesheet1, null, null, $parameters))) {
        // Transformation failed.
        toolbook_wordimport_debug_unlink($tempwordmlfilename);
        throw new moodle_exception('transformationfailed', 'toolbook_wordimport', $tempwordmlfilename);
    }
    toolbook_wordimport_debug_unlink($tempwordmlfilename);
    debugging(__FUNCTION__ . ":" . __LINE__ . ": Import XSLT Pass 1 succeeded, XHTML output fragment = " .
        str_replace("\n", "", substr($xsltoutput, 0, 200)), DEBUG_WORDIMPORT);

    // Write output of Pass 1 to a temporary file, for use in Pass 2.
    $tempxhtmlfilename = $CFG->dataroot . '/temp/' . basename($filename, ".tmp") . ".if1";
    if (($nbytes = file_put_contents($tempxhtmlfilename, $xsltoutput )) == 0) {
        // Cannot save the file.
        throw new moodle_exception('cannotsavefile', 'error', $tempxhtmlfilename);
    }

    // Pass 2 - tidy up linear XHTML a bit.
    debugging(__FUNCTION__ . ":" . __LINE__ . ": XSLT Pass 2 using \"" . $word2xmlstylesheet2 . "\"", DEBUG_WORDIMPORT);
    if (!($xsltoutput = xslt_process($xsltproc, $tempxhtmlfilename, $word2xmlstylesheet2, null, null, $parameters))) {
        // Transformation failed.
        toolbook_wordimport_debug_unlink($tempxhtmlfilename);
        throw new moodle_exception('transformationfailed', 'toolbook_wordimport', $tempxhtmlfilename);
    }
    toolbook_wordimport_debug_unlink($tempxhtmlfilename);

    // Strip out superfluous namespace declarations on paragraph elements, which Moodle 2.7+ on Windows seems to throw in.
    $xsltoutput = str_replace('<p xmlns="http://www.w3.org/1999/xhtml"', '<p', $xsltoutput);
    $xsltoutput = str_replace(' xmlns=""', '', $xsltoutput);
    // Remove 'mml:' prefix from child MathML element and attributes for compatibility with MathJax.
    $xsltoutput = str_replace('<mml:', '<', $xsltoutput);
    $xsltoutput = str_replace('</mml:', '</', $xsltoutput);
    $xsltoutput = str_replace(' mathvariant="normal"', '', $xsltoutput);
    $xsltoutput = str_replace(' xmlns:mml="http://www.w3.org/1998/Math/MathML"', '', $xsltoutput);
    $xsltoutput = str_replace('<math>', '<math xmlns="http://www.w3.org/1998/Math/MathML">', $xsltoutput);

    // Keep the converted XHTML file for debugging if developer debugging enabled.
    if (debugging(null, DEBUG_WORDIMPORT)) {
        $tempxhtmlfilename = $CFG->dataroot . '/temp/' . basename($filename, ".tmp") . ".xhtml";
        file_put_contents($tempxhtmlfilename, $xsltoutput);
    }

    return $xsltoutput;
}   // End function convert_to_xhtml.

/**
 * Delete temporary files if debugging disabled
 *
 * @param string $filename name of file to be deleted
 * @return void
 */
function toolbook_wordimport_debug_unlink($filename) {
    if (DEBUG_WORDIMPORT == 0) {
        unlink($filename);
    }
}
