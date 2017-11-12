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
require_once(dirname(__FILE__).'/xslemulatexslt.inc');
require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->dirroot.'/mod/book/lib.php');
require_once($CFG->dirroot.'/mod/book/locallib.php');
require_once($CFG->dirroot.'/mod/book/tool/importhtml/locallib.php');

if (!function_exists('create_module')) {        // Moodle <= 2.4.
    /**
     * Define dummy create_module function for Moodle 2.3
     *
     * @param array $data
     * @return null
     */
    function create_module($data) {
        return null;
    }
}

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
    $htmlcontent = booktool_wordimport_convert_to_xhtml($wordfilename, $imagesforzipping);

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


/**
 * Extract the WordProcessingML XML files from the .docx file, and use a sequence of XSLT
 * steps to convert it into XHTML files
 *
 * @param string $filename Word file
 * @param array $imagesforzipping array to store embedded image files
 * @return string XHTML content extracted from Word file and split into files
 */
function booktool_wordimport_convert_to_xhtml($filename, &$imagesforzipping) {
    global $CFG;

    $word2xmlstylesheet1 = __DIR__ . "/wordml2xhtmlpass1.xsl"; // Convert WordML into basic XHTML.
    $word2xmlstylesheet2 = __DIR__ . "/wordml2xhtmlpass2.xsl"; // Refine basic XHTML into Word-compatible XHTML.

    // @codingStandardsIgnoreLine debugging(__FUNCTION__ . ":" . __LINE__ . ": filename = \"{$filename}\"", DEBUG_WORDIMPORT);
    // Check that we can unzip the Word .docx file into its component files.
    $zipres = zip_open($filename);
    if (!is_resource($zipres)) {
        // Cannot unzip file.
        booktool_wordimport_debug_unlink($filename);
        throw new moodle_exception('cannotunzipfile', 'error');
    }

    // Check that XSLT is installed.
    if (!class_exists('XSLTProcessor') || !function_exists('xslt_create')) {
        // PHP extension 'xsl' is required for this action.
        throw new moodle_exception(get_string('extensionrequired', 'tool_xmldb', 'xsl'));
    }

    // Uncomment next line to give XSLT as much memory as possible, to enable larger Word files to be imported.
    // @codingStandardsIgnoreLine raise_memory_limit(MEMORY_HUGE);

    if (!file_exists($word2xmlstylesheet1)) {
        // XSLT stylesheet to transform WordML into XHTML is missing.
        throw new moodle_exception('filemissing', 'moodle', $word2xmlstylesheet1);
    }

    // Set common parameters for all XSLT transformations.
    $parameters = array (
        'moodle_language' => current_language(),
        'moodle_textdirection' => (right_to_left()) ? 'rtl' : 'ltr',
        'heading1stylelevel' => '3',
        'pluginname' => 'booktool_wordimport', // Include plugin name to control image data handling inside XSLT.
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
                // @codingStandardsIgnoreLine debugging(__FUNCTION__ . ":" . __LINE__ . ": added \"{$imagename}\" to Zip file", DEBUG_WORDIMPORT);
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
                // @codingStandardsIgnoreLine case "word/_rels/settings.xml.rels":
                    // @codingStandardsIgnoreLine $wordmldata .= "<settingsLinks>" . $xmlfiledata . "</settingsLinks>\n";
                    // @codingStandardsIgnoreLine break;
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
    $tempwordmlfilename = $CFG->tempdir . DIRECTORY_SEPARATOR . basename($filename, ".tmp") . ".wml";
    if ((file_put_contents($tempwordmlfilename, $wordmldata)) == 0) {
        // Cannot save the file.
        throw new moodle_exception('cannotsavefile', 'error', $tempwordmlfilename);
    }

    $xsltproc = xslt_create();
    if (!($xsltoutput = xslt_process($xsltproc, $tempwordmlfilename, $word2xmlstylesheet1, null, null, $parameters))) {
        // Transformation failed.
        booktool_wordimport_debug_unlink($tempwordmlfilename);
        throw new moodle_exception('transformationfailed', 'booktool_wordimport', $tempwordmlfilename);
    }
    booktool_wordimport_debug_unlink($tempwordmlfilename);
    // @codingStandardsIgnoreLine debugging(__FUNCTION__ . ":" . __LINE__ . ": Import XSLT Pass 1 succeeded, output = " .
    // @codingStandardsIgnoreLine     str_replace("\n", "", substr($xsltoutput, 0, 200)), DEBUG_WORDIMPORT);

    // Write output of Pass 1 to a temporary file, for use in Pass 2.
    $tempxhtmlfilename = $CFG->tempdir . DIRECTORY_SEPARATOR . basename($filename, ".tmp") . ".if1";
    $xsltoutput = str_replace('<p xmlns="http://www.w3.org/1999/xhtml"', '<p', $xsltoutput);
    $xsltoutput = str_replace('<span xmlns="http://www.w3.org/1999/xhtml"', '<span', $xsltoutput);
    $xsltoutput = str_replace(' xmlns=""', '', $xsltoutput);
    if ((file_put_contents($tempxhtmlfilename, $xsltoutput )) == 0) {
        // Cannot save the file.
        throw new moodle_exception('cannotsavefile', 'error', $tempxhtmlfilename);
    }

    // Pass 2 - tidy up linear XHTML a bit.
    if (!($xsltoutput = xslt_process($xsltproc, $tempxhtmlfilename, $word2xmlstylesheet2, null, null, $parameters))) {
        // Transformation failed.
        booktool_wordimport_debug_unlink($tempxhtmlfilename);
        throw new moodle_exception('transformationfailed', 'booktool_wordimport', $tempxhtmlfilename);
    }
    booktool_wordimport_debug_unlink($tempxhtmlfilename);

    // Strip out superfluous namespace declarations on paragraph elements, which Moodle 2.7+ on Windows seems to throw in.
    $xsltoutput = str_replace('<p xmlns="http://www.w3.org/1999/xhtml"', '<p', $xsltoutput);
    $xsltoutput = str_replace('<span xmlns="http://www.w3.org/1999/xhtml"', '<span', $xsltoutput);
    $xsltoutput = str_replace(' xmlns=""', '', $xsltoutput);
    // Remove 'mml:' prefix from child MathML element and attributes for compatibility with MathJax.
    $xsltoutput = str_replace('<mml:', '<', $xsltoutput);
    $xsltoutput = str_replace('</mml:', '</', $xsltoutput);
    $xsltoutput = str_replace(' mathvariant="normal"', '', $xsltoutput);
    $xsltoutput = str_replace(' xmlns:mml="http://www.w3.org/1998/Math/MathML"', '', $xsltoutput);
    $xsltoutput = str_replace('<math>', '<math xmlns="http://www.w3.org/1998/Math/MathML">', $xsltoutput);

    // @codingStandardsIgnoreLine debugging(__FUNCTION__ . ":" . __LINE__ . ": Import XSLT Pass 2 succeeded, output = " .
    // @codingStandardsIgnoreLine     str_replace("\n", "", substr($xsltoutput, 500, 2000)), DEBUG_WORDIMPORT);

    // Keep the converted XHTML file for debugging if developer debugging enabled.
    if (DEBUG_WORDIMPORT == DEBUG_DEVELOPER and debugging(null, DEBUG_DEVELOPER)) {
        $tempxhtmlfilename = $CFG->tempdir . DIRECTORY_SEPARATOR . basename($filename, ".tmp") . ".xhtml";
        file_put_contents($tempxhtmlfilename, $xsltoutput);
    }

    return $xsltoutput;
}   // End function booktool_wordimport_convert_to_xhtml.


/**
 * Export book HTML into Word-compatible XHTML format
 *
 * Use an XSLT script to do the job, as it is much easier to implement this,
 * and Moodle sites are guaranteed to have an XSLT processor available (I think).
 *
 * @param string $content all HTML content from a book or chapter
 * @return string Word-compatible XHTML text
 */
function booktool_wordimport_export( $content ) {
    global $CFG, $USER, $COURSE, $OUTPUT;

    /*
     * @var string export template with Word-compatible CSS style definitions
    */
    $wordfiletemplate = 'wordfiletemplate.html';
    /*
     * @var string Stylesheet to export XHTML into Word-compatible XHTML
    */
    $exportstylesheet = 'xhtml2wordpass2.xsl';

    // @codingStandardsIgnoreLine debugging(__FUNCTION__ . '($content = "' . str_replace("\n", "", substr($content, 80, 500)) . ' ...")', DEBUG_WORDIMPORT);

    // XHTML template for Word file CSS styles formatting.
    $htmltemplatefilepath = __DIR__ . "/" . $wordfiletemplate;
    $stylesheet = __DIR__ . "/" . $exportstylesheet;

    // Check that XSLT is installed, and the XSLT stylesheet and XHTML template are present.
    if (!class_exists('XSLTProcessor') || !function_exists('xslt_create')) {
        echo $OUTPUT->notification(get_string('xsltunavailable', 'booktool_wordimport'));
        return false;
    } else if (!file_exists($stylesheet)) {
        // Stylesheet to transform Moodle Question XML into Word doesn't exist.
        echo $OUTPUT->notification(get_string('stylesheetunavailable', 'booktool_wordimport', $stylesheet));
        return false;
    }

    // Get a temporary file name for storing the book/chapter XHTML content to transform.
    if (!($tempxmlfilename = tempnam($CFG->tempdir . DIRECTORY_SEPARATOR, "b2w-"))) {
        echo $OUTPUT->notification(get_string('cannotopentempfile', 'booktool_wordimport', basename($tempxmlfilename)));
        return false;
    }
    unlink($tempxmlfilename);
    $tempxhtmlfilename = $CFG->tempdir . DIRECTORY_SEPARATOR . basename($tempxmlfilename, ".tmp") . ".xhtm";

    // Uncomment next line to give XSLT as much memory as possible, to enable larger Word files to be exported.
    // @codingStandardsIgnoreLine raise_memory_limit(MEMORY_HUGE);

    $cleancontent = booktool_wordimport_clean_html_text($content);

    // Set the offset for heading styles, default is h3 becomes Heading 1.
    $heading1styleoffset = '3';
    if (strpos($cleancontent, '<div class="lucimoo">')) {
        $heading1styleoffset = '1';
    }

    // Set parameters for XSLT transformation. Note that we cannot use $arguments though.
    $parameters = array (
        'course_id' => $COURSE->id,
        'course_name' => $COURSE->fullname,
        'author_name' => $USER->firstname . ' ' . $USER->lastname,
        'moodle_country' => $USER->country,
        'moodle_language' => current_language(),
        'moodle_textdirection' => (right_to_left()) ? 'rtl' : 'ltr',
        'moodle_release' => $CFG->release,
        'moodle_url' => $CFG->wwwroot . "/",
        'moodle_username' => $USER->username,
        'debug_flag' => debugging('', DEBUG_WORDIMPORT),
        'heading1stylelevel' => $heading1styleoffset,
        'transformationfailed' => get_string('transformationfailed', 'booktool_wordimport', $exportstylesheet)
    );

    // Write the book contents and the HTML template to a file.
    $xhtmloutput = "<container>\n<container><html xmlns='http://www.w3.org/1999/xhtml'><body>" .
            $cleancontent . "</body></html></container>\n<htmltemplate>\n" .
            file_get_contents($htmltemplatefilepath) . "\n</htmltemplate>\n</container>";
    if ((file_put_contents($tempxhtmlfilename, $xhtmloutput)) == 0) {
        echo $OUTPUT->notification(get_string('cannotwritetotempfile', 'booktool_wordimport', basename($tempxhtmlfilename)));
        return false;
    }

    // Prepare for Pass 2 XSLT transformation (Pass 1 not needed because books, unlike questions, are already HTML.
    $stylesheet = __DIR__ . "/" . $exportstylesheet;
    $xsltproc = xslt_create();
    if (!($xsltoutput = xslt_process($xsltproc, $tempxhtmlfilename, $stylesheet, null, null, $parameters))) {
        echo $OUTPUT->notification(get_string('transformationfailed', 'booktool_wordimport', $stylesheet));
        booktool_wordimport_debug_unlink($tempxhtmlfilename);
        return false;
    }
    booktool_wordimport_debug_unlink($tempxhtmlfilename);

    // Strip out any redundant namespace attributes, which XSLT on Windows seems to add.
    $xsltoutput = str_replace(' xmlns=""', '', $xsltoutput);
    $xsltoutput = str_replace(' xmlns="http://www.w3.org/1999/xhtml"', '', $xsltoutput);
    // Unescape double minuses if they were substituted during CDATA content clean-up.
    $xsltoutput = str_replace("WORDIMPORTMinusMinus", "--", $xsltoutput);

    // Strip off the XML declaration, if present, since Word doesn't like it.
    if (strncasecmp($xsltoutput, "<?xml ", 5) == 0) {
        $content = substr($xsltoutput, strpos($xsltoutput, "\n"));
    } else {
        $content = $xsltoutput;
    }

    return $content;
}   // End booktool_wordimport_export function.

/**
 * Get images and write them as base64 inside the HTML content
 *
 * A string containing the HTML with embedded base64 images is returned
 *
 * @param string $contextid the context ID
 * @param string $filearea filearea: chapter or intro
 * @param string $chapterid the chapter ID (optional)
 * @return string the modified HTML with embedded images
 */
function booktool_wordimport_base64_images($contextid, $filearea, $chapterid = null) {
    // Get the list of files embedded in the book or chapter.
    // Note that this will break on images in the Book Intro section.
    $imagestring = '';
    $fs = get_file_storage();
    if ($filearea == 'intro') {
        $files = $fs->get_area_files($contextid, 'mod_book', $filearea);
    } else {
        $files = $fs->get_area_files($contextid, 'mod_book', $filearea, $chapterid);
    }
    foreach ($files as $fileinfo) {
        // Process image files, converting them into Base64 encoding.
        debugging(__FUNCTION__ . ": $filearea file: " . $fileinfo->get_filename(), DEBUG_WORDIMPORT);
        $fileext = strtolower(pathinfo($fileinfo->get_filename(), PATHINFO_EXTENSION));
        if ($fileext == 'png' or $fileext == 'jpg' or $fileext == 'jpeg' or $fileext == 'gif') {
            $filename = $fileinfo->get_filename();
            $filetype = ($fileext == 'jpg') ? 'jpeg' : $fileext;
            $fileitemid = $fileinfo->get_itemid();
            $filepath = $fileinfo->get_filepath();
            $filedata = $fs->get_file($contextid, 'mod_book', $filearea, $fileitemid, $filepath, $filename);

            if (!$filedata === false) {
                $base64data = base64_encode($filedata->get_content());
                $filedata = 'data:image/' . $filetype . ';base64,' . $base64data;
                // Embed the image name and data into the HTML.
                $imagestring .= '<img title="' . $filename . '" src="' . $filedata . '"/>';
            }
        }
    }

    if ($imagestring != '') {
        return '<div class="ImageFile">' . $imagestring . '</div>';
    }
    return '';
}


/**
 * Clean HTML content
 *
 * A string containing clean XHTML is returned
 *
 * @param string $cdatastring XHTML from inside a CDATA_SECTION in a question text element
 * @return string
 */
function booktool_wordimport_clean_html_text($cdatastring) {
    // Escape double minuses, which cause XSLT processing to fail.
    $cdatastring = str_replace("--", "WORDIMPORTMinusMinus", $cdatastring);

    // Wrap the string in a HTML wrapper, load it into a new DOM document as HTML, but save as XML.
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><html><body>' . $cdatastring . '</body></html>');
    $doc->getElementsByTagName('html')->item(0)->setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
    $xml = $doc->saveXML();

    $bodystart = stripos($xml, '<body>') + strlen('<body>');
    $bodylength = strripos($xml, '</body>') - $bodystart;
    if ($bodystart || $bodylength) {
        $cleanxhtml = substr($xml, $bodystart, $bodylength);
    } else {
        $cleanxhtml = $cdatastring;
    }

    // Fix up filenames after @@PLUGINFILE@@ to replace URL-encoded characters with ordinary characters.
    $foundpluginfilenames = preg_match_all('~(.*?)<img src="@@PLUGINFILE@@/([^"]*)(.*)~s', $cleanxhtml,
                                $pluginfilematches, PREG_SET_ORDER);
    $nummatches = count($pluginfilematches);
    if ($foundpluginfilenames and $foundpluginfilenames != 0) {
        $urldecodedstring = "";
        // Process the possibly-URL-escaped filename so that it matches the name in the file element.
        for ($i = 0; $i < $nummatches; $i++) {
            // Decode the filename and add the surrounding text.
            $decodedfilename = urldecode($pluginfilematches[$i][2]);
            $urldecodedstring .= $pluginfilematches[$i][1] . '<img src="@@PLUGINFILE@@/' . $decodedfilename .
                                    $pluginfilematches[$i][3];
        }
        $cleanxhtml = $urldecodedstring;
    }

    // Strip soft hyphens (0xAD, or decimal 173).
    $cleanxhtml = preg_replace('/\xad/u', '', $cleanxhtml);

    return $cleanxhtml;
}


/**
 * Delete temporary files if debugging disabled
 *
 * @param string $filename name of file to be deleted
 * @return void
 */
function booktool_wordimport_debug_unlink($filename) {
    if (DEBUG_WORDIMPORT !== DEBUG_DEVELOPER or !(debugging(null, DEBUG_DEVELOPER))) {
        unlink($filename);
    }
}
