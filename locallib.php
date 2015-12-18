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
function toolbook_wordimport_import_word($package, $book, $context, $splitonsubheadings, $verbose = false) {
    global $CFG, $OUTPUT;

    if (!$tmpfilename = $package->copy_content_to_temp()) {
        // Cannot save file.
        throw new moodle_exception(get_string('errorcreatingfile', 'error', $package->get_filename()));
    }
    // Process the Word file into a HTML file and images.
    $imagesforzipping = array();
    $html_content = toolbook_wordimport_convert_to_xhtml($tmpfilename, $splitonsubheadings, $imagesforzipping);


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

    // Split the single HTML file into multiple chapters based on 
    $h1matches = null;
    $chapterfilenames = array();
    $chapternames = array();
    $foundh1matches = preg_match('~<h1[^>]*>(.+)</h1>~is', $html_content, $h1matches);
    $nummatches = count($h1matches);
    debugging(__FUNCTION__ . ":" . __LINE__ . ": found: {$foundh1matches}; num = {$nummatches}", DEBUG_WORDIMPORT);
    if ($foundh1matches and $foundh1matches != 0) {
        // Process the possibly-URL-escaped filename so that it matches the name in the file element.
        for ($i = 0; $i < $nummatches; $i++) {
            // Assign a filename and save the heading.
            $chapterfilenames[$i] = "chap" . sprintf("%04d", $i) . ".htm";
            $chapternames[$i] = $h1matches[$i];

            debugging(__FUNCTION__ . ":" . __LINE__ . ": h1 ({$chapterfilenames[$i]}) = \"{$h1matches[$i]}\"", DEBUG_WORDIMPORT);

            $zipfile->add_file_from_string(toolbook_importhtml_parse_body($html_content), $h1matches[$i]);
        }
    }

    $zipfile->add_file_from_string("index.htm", $html_content);

    $chapterfiles = toolbook_wordimport_get_chapter_files($zipfilename, $context, $splitonsubheadings);
    $chapternames = toolbook_wordimport_get_chapter_names($context);
    toolbook_wordimport_delete_files($context);
    echo $OUTPUT->notification(get_string('importing', 'booktool_importhtml'), 'notifysuccess');
    $package->archive_file($zipfile);
    return toolbook_wordimport_import_chapters($package, 2, $chapterfiles, $chapternames, $book, $context, $verbose);
}

/**
 * Unzip Word file
 *
 * @param stored_file $package Word file
 * @param context_module $context
 */
function toolbook_wordimport_unzip_files($package, $context) {
    $packer = get_file_packer('application/zip');
    $files = $package->list_files($packer);
    $found = false;
    foreach ($files as $file) {
        if (empty($file->pathname)) {
            continue;
        }
        if ($file->pathname == 'META-INF/container.xml') {
            $found = true;
            break;
        }
    }
    if (!$found) {
        return false;
    }

    toolbook_wordimport_delete_files($context);
    $package->extract_to_storage($packer, $context->id, 'mod_book',
                                 'wordimporttemp', 0, '/');
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
 * Get OPF DOM and OPF root path from previously unzipped Word file
 *
 * @param context_module $context
 *
 * @return array with DOM document and string
 */
function toolbook_wordimport_get_opf($context) {
    if (!defined('CNT_NS')) {
        define('CNT_NS', 'urn:oasis:names:tc:opendocument:xmlns:container');
    }
    if (!defined('OPF_NS')) {
        define('OPF_NS', 'http://www.idpf.org/2007/opf');
    }
    if (!defined('HTML_NS')) {
        define('HTML_NS', 'http://www.w3.org/1999/xhtml');
    }
    if (!defined('NCX_NS')) {
        define('NCX_NS', 'http://www.daisy.org/z3986/2005/ncx/');
    }

    $fs = get_file_storage();

    // Container.
    $filehash = $fs->get_pathname_hash($context->id, 'mod_book',
                                       'wordimporttemp', 0, '/',
                                       'META-INF/container.xml');
    $file = $fs->get_file_by_hash($filehash);
    if (!$file) {
        return false;
    }
    $doc = new DOMDocument();
    if (!$doc->loadXML($file->get_content())) {
        return false;
    }
    $items = $doc->getElementsByTagNameNS(CNT_NS, 'rootfile');
    if (!$items) {
        return false;
    }
    $rootfile = $items->item(0)->getAttribute('full-path');
    $opfroot = dirname($rootfile) . '/';
    if ($opfroot == './') {
        $opfroot = '';
    }

    // OPF file.
    $filehash = $fs->get_pathname_hash($context->id, 'mod_book',
                                       'wordimporttemp', 0, '/', $rootfile);
    $file = $fs->get_file_by_hash($filehash);
    if (!$file) {
        return false;
    }
    $doc = new DOMDocument();
    if (!$doc->loadXML($file->get_content())) {
        return false;
    }

    return array($doc, $opfroot);
}

/**
 * Get title from previously unzipped Word file
 *
 * @param context_module $context
 *
 * @return string or null
 */
function toolbook_wordimport_get_title($context) {
    list($opf, $opfroot) = toolbook_wordimport_get_opf($context);
    if ($opf) {
        $items = $opf->getElementsByTagName('title');
        if ($items) {
            return $items->item(0)->nodeValue;
        }
    }
    return null;
}

/**
 * Get chapter names from previously unzipped Word file
 *
 * @param context_module $context
 *
 * @return array mapping filepath => chapter name
 */
function toolbook_wordimport_get_chapter_names($context) {
    $chapternames = array();

    list($opf, $opfroot) = toolbook_wordimport_get_opf($context);
    if (!$opf) {
        return $chapternames;
    }

    // Find nav document (EPUB 3).
    $nav = null;
    $items = $opf->getElementsByTagNameNS(OPF_NS, 'item');
    foreach ($items as $item) {
        if ($item->hasAttribute('properties') and $item->hasAttribute('href')
            and ($item->getAttribute('properties') == 'nav')) {
            $nav = $item->getAttribute('href');
        }
    }
    if ($nav) {
        $fs = get_file_storage();

        // Container.
        $filehash = $fs->get_pathname_hash($context->id, 'mod_book',
                                           'wordimporttemp', 0, '/',
                                           $opfroot . $nav);
        $file = $fs->get_file_by_hash($filehash);
        if ($file) {
            $doc = new DOMDocument();
            if ($doc->loadXML($file->get_content())) {
                $navroot = dirname($opfroot . $nav);
                if ($navroot == '.') {
                    $navroot = '';
                } else {
                    $navroot .= '/';
                }

                // Find links.
                $items = $doc->getElementsByTagNameNS(HTML_NS, 'a');
                foreach ($items as $item) {
                    if ($item->hasAttribute('href')) {
                        $href = $item->getAttribute('href');
                        $href = explode('#', $href, 2);
                        $href = $navroot . $href[0];
                        if (!array_key_exists($href, $chapternames)) {
                            $name = $item->nodeValue;
                            $chapternames[$href] = $name;
                        }
                    }
                }
            }
        }
    }

    // Find ncx document (EPUB 2).
    $ncx = null;
    $el = $opf->getElementsByTagNameNS(OPF_NS, 'spine');
    if ($el and $el->item(0)->hasAttribute('toc')) {
        $ncxid = $el->item(0)->getAttribute('toc');

        $el = null;
        $items = $opf->getElementsByTagNameNS(OPF_NS, 'item');
        foreach ($items as $item) {
            if ($item->hasAttribute('id') and
                ($item->getAttribute('id') == $ncxid)) {
                if ($item->hasAttribute('href')) {
                    $ncx = $item->getAttribute('href');
                }
                break;
            }
        }
    }

    if ($ncx) {
        $fs = get_file_storage();

        // Container.
        $filehash = $fs->get_pathname_hash($context->id, 'mod_book',
                                           'wordimporttemp', 0, '/',
                                           $opfroot . $ncx);
        $file = $fs->get_file_by_hash($filehash);
        if ($file) {
            $doc = new DOMDocument();
            if ($doc->loadXML($file->get_content())) {
                $ncxroot = dirname($opfroot . $ncx);
                if ($ncxroot == '.') {
                    $ncxroot = '';
                } else {
                    $ncxroot .= '/';
                }

                // Find links.
                $items = $doc->getElementsByTagNameNS(NCX_NS, 'navPoint');
                foreach ($items as $item) {
                    $el = $item->getElementsByTagNameNS(NCX_NS, 'content');
                    if (!($el and $el->item(0)->hasAttribute('src'))) {
                        continue;
                    }
                    $href = $el->item(0)->getAttribute('src');
                    $href = explode('#', $href, 2);
                    $href = $ncxroot . $href[0];
                    if (!array_key_exists($href, $chapternames)) {
                        $el = $item->getElementsByTagNameNS(NCX_NS, 'navLabel');
                        if (!$el) {
                            continue;
                        }
                        $el = $el->item(0)->getElementsByTagNameNS(NCX_NS, 'text');
                        if (!$el) {
                            continue;
                        }
                        $name = $el->item(0)->nodeValue;

                        $chapternames[$href] = $name;
                    }
                }
            }
        }
    }

    return $chapternames;
}

/**
 * Returns all the html files from a Word file
 *
 * @param stored_file $package EPUB file to be processed
 * @param context_module $context
 *
 * @return array the html files found in the Word file
 */
function toolbook_wordimport_get_chapter_files($package, $context) {
    $chapterfiles = array();

    list($opf, $opfroot) = toolbook_wordimport_get_opf($context);
    if (!$opf) {
        return $chapterfiles;
    }

    // Manifest.
    $manifest = array();
    $items = $opf->getElementsByTagNameNS(OPF_NS, 'item');
    foreach ($items as $item) {
        if ($item->hasAttribute('id') and $item->hasAttribute('href') and
            $item->hasAttribute('media-type')) {
            $manifest[$item->getAttribute('id')] = array('href' => $item->getAttribute('href'),
                      'mediatype' => $item->getAttribute('media-type'));
        }
    }

    // Spine.
    $items = $opf->getElementsByTagNameNS(OPF_NS, 'itemref');
    foreach ($items as $item) {
        if ($item->hasAttribute('idref')) {
            // Maybe exclude if $item->getAttribute('linear') == 'no'?
            $it = $manifest[$item->getAttribute('idref')];
            if ($it['mediatype'] == 'application/xhtml+xml' or
                $it['mediatype'] == 'text/html') {
                $filepath = $opfroot . $it['href'];
                // FIXME $filepath should be normalized (i.e. '/..' removed).
                $chapterfiles[] = (object) array('pathname' => $filepath);
            }
        }
    }

    return $chapterfiles;
}

/**
 * Import HTML pages packaged into one zip archive
 *
 * @param stored_file $package
 * @param string $type type of the package ('typezipdirs' or 'typezipfiles')
 * @param array $chapterfiles
 * @param array $chapternames
 * @param stdClass $book
 * @param context_module $context
 * @param bool $verbose
 */
function toolbook_wordimport_import_chapters($package, $type, $chapterfiles,
                                             $chapternames, $book, $context, $verbose = true) {
    global $DB, $OUTPUT;

    $fs = get_file_storage();
    $packer = get_file_packer('application/zip');
    $fs->delete_area_files($context->id, 'mod_book', 'wordimporttemp', 0);
    $package->extract_to_storage($packer, $context->id, 'mod_book', 'wordimporttemp', 0, '/');
    // @codingStandardsIgnoreLine $datafiles = $fs->get_area_files($context->id, 'mod_book', 'wordimporttemp', 0, 'id', false);
    // @codingStandardsIgnoreLine echo "<pre>";p(var_export($datafiles, true));

    $chapters = array();

    if ($verbose) {
        echo $OUTPUT->notification(get_string('importing', 'booktool_importhtml'), 'notifysuccess');
    }
    if ($type == 0) {
        $chapterfile = reset($chapterfiles);
        if ($file = $fs->get_file_by_hash("$context->id/mod_book/importhtmltemp/0/$chapterfile->pathname")) {
            $htmlcontent = toolbook_importhtml_fix_encoding($file->get_content());
            $htmlchapters = toolbook_importhtml_parse_headings(toolbook_importhtml_parse_body($htmlcontent));
            // TODO: process h1 as main chapter and h2 as subchapters.
        }
    } else {
        foreach ($chapterfiles as $chapterfile) {
            if ($file = $fs->get_file_by_hash(sha1("/$context->id/mod_book/importhtmltemp/0/$chapterfile->pathname"))) {
                $chapter = new stdClass();
                $htmlcontent = toolbook_importhtml_fix_encoding($file->get_content());

                $chapter->bookid        = $book->id;
                $chapter->pagenum       = $DB->get_field_sql('SELECT MAX(pagenum) FROM {book_chapters} WHERE bookid = ?',
                                                    array($book->id)) + 1;
                $chapter->importsrc     = '/'.$chapterfile->pathname;
                $chapter->content       = '';
                $chapter->content       .= '<div class="wordimport">';
                $chapter->content       .= toolbook_importhtml_parse_body($htmlcontent);
                $chapter->content       .= '</div>';
                if (array_key_exists($chapterfile->pathname, $chapternames)) {
                    $chapter->title = $chapternames[$chapterfile->pathname];
                } else {
                    $chapter->title = toolbook_importhtml_parse_title($htmlcontent, $chapterfile->pathname);
                }
                $chapter->title = trim($chapter->title);
                if ($chapter->title == '') {
                    if (array_key_exists(0, $chapternames)) {
                        $chapter->title = trim($chapternames[0]);
                    }
                }
                if ($chapter->title == '') {
                    $chapter->title = '*';
                }
                $chapter->title = substr($chapter->title, 0, 250);
                $chapter->contentformat = FORMAT_HTML;
                $chapter->hidden        = 0;
                $chapter->timecreated   = time();
                $chapter->timemodified  = $chapter->timecreated;
                if (preg_match('/_sub(\/|\.htm)/i', $chapter->importsrc)) {
                    // If filename or directory ends with *_sub treat as subchapters.
                    $chapter->subchapter = 1;
                } else {
                    $chapter->subchapter = 0;
                }

                $chapter->id = $DB->insert_record('book_chapters', $chapter);
                $chapters[$chapter->id] = $chapter;

                // @codingStandardsIgnoreStart
                // add_to_log($book->course, 'book', 'add chapter', 'view.php?id='.$context->instanceid.'&chapterid='.$chapter->id, $chapter->id, $context->instanceid);
                // @codingStandardsIgnoreEnd
            }
        }
    }

    if ($verbose) {
        echo $OUTPUT->notification(get_string('relinking', 'booktool_importhtml'), 'notifysuccess');
    }
    $allchapters = $DB->get_records('book_chapters', array('bookid' => $book->id), 'pagenum');
    foreach ($chapters as $chapter) {
        // Find references to all files and copy them + relink them.
        $matches = null;
        if (preg_match_all('/(src|codebase|name|href)\s*=\s*[\'"]([^\'"#]+)(#[^\'"]*)?[\'"]/i', $chapter->content, $matches)) {
            $filerecord = array('contextid' => $context->id,
                                'component' => 'mod_book',
                                'filearea' => 'chapter',
                                'itemid' => $chapter->id);
            foreach ($matches[0] as $i => $match) {
                $name = rawurldecode($matches[2][$i]);
                $filepath = dirname($chapter->importsrc) . '/' . $name;
                $filepath = toolbook_importhtml_fix_path($filepath);

                if (strtolower($matches[1][$i]) === 'href') {
                    // Skip linked html files, we will try chapter relinking later.
                    foreach ($allchapters as $target) {
                        if ($target->importsrc === $filepath) {
                            continue 2;
                        }
                    }
                }

                if ($file = $fs->get_file_by_hash(sha1("/$context->id/mod_book/importhtmltemp/0$filepath"))) {
                    if (!$oldfile = $fs->get_file_by_hash(sha1("/$context->id/mod_book/chapter/$chapter->id$filepath"))) {
                        $text = '';
                        if ($text) {
                            $filerecord['filepath'] = dirname($filepath);
                            if (substr($filerecord['filepath'], -1) != '/') {
                                $filerecord['filepath'] .= '/';
                            }
                            $filerecord['filename'] = basename($filepath);
                            $fs->create_file_from_string($filerecord, $text);
                            unset($filerecord['filepath']);
                            unset($filerecord['filename']);
                        } else {
                            $fs->create_file_from_storedfile($filerecord,
                                                             $file);
                        }
                    }
                    $chapter->content = str_replace($match, $matches[1][$i].'="@@PLUGINFILE@@'.$filepath.'"', $chapter->content);
                }
            }
            $DB->set_field('book_chapters', 'content', $chapter->content, array('id' => $chapter->id));
        }
    }
    unset($chapters);

    // Relink relative HTML links.
    $allchapters = $DB->get_records('book_chapters', array('bookid' => $book->id), 'pagenum');
    foreach ($allchapters as $chapter) {
        $newcontent = $chapter->content;
        $matches = null;
        if (preg_match_all('/(href)\s*=\s*[\'"]([^\'"#]+)(#([^\'"]*))?[\'"]/i',
                           $chapter->content, $matches)) {
            foreach ($matches[0] as $i => $match) {
                if (strpos($matches[2][$i], ':') !== false or strpos($matches[2][$i], '@') !== false) {
                    // It is either absolute or pluginfile link.
                    continue;
                }
                $chapterpath = dirname($chapter->importsrc).'/'.$matches[2][$i];
                $chapterpath = toolbook_importhtml_fix_path($chapterpath);
                foreach ($allchapters as $target) {
                    if ($target->importsrc === $chapterpath) {
                        $anchor = null;
                        if ($matches[4][$i]) {
                            $anchor = $matches[4][$i];
                        }
                        $nurl = new moodle_url('/mod/book/view.php',
                                               array('id' => $context->instanceid,
                                                     'chapterid' => $target->id),
                                               $anchor);
                        $newcontent = str_replace($match, 'href="'. $nurl .'"',
                                                  $newcontent);
                    }
                }
            }
        }
        if ($newcontent !== $chapter->content) {
            $DB->set_field('book_chapters', 'content', $newcontent, array('id' => $chapter->id));
        }
    }

    // @codingStandardsIgnoreLine add_to_log($book->course, 'course', 'update mod', '../mod/book/view.php?id='.$context->instanceid, 'book '.$book->id);
    $fs->delete_area_files($context->id, 'mod_book', 'wordimporttemp', 0);

    // Update the revision flag - this takes a long time, better to refetch the current value.
    $book = $DB->get_record('book', array('id' => $book->id));
    $DB->set_field('book', 'revision', $book->revision + 1, array('id' => $book->id));
}

/**
 * Extract the WordProcessingML XML files from the .docx file, and use a sequence of XSLT
 * steps to convert it into XHTML files
 *
 * @param string $package Word file package, now unzipped into its component files
 * @param int $usercontextid ID of draft file area where images should be stored
 * @param int $draftitemid ID of particular group in draft file area where images should be stored
 * @return array XHTML content extracted from Word file and split into files
 */
function toolbook_wordimport_convert_to_xhtml_files($package, $usercontextid, $splitonsubheadings, $draftitemid) {
    return;
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

    // Add images section and close the merged XML file.
    // $wordmldata .= "<imagesContainer>\n" . $imagestring . "</imagesContainer>\n";
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
