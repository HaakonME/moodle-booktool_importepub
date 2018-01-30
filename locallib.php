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
 * Import EPUB library.
 *
 * @package    booktool
 * @subpackage importepub
 * @copyright  2013-2018 Mikael Ylikoski
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* This file contains code based on mod/book/tool/importhtml/locallib.php
 * (copyright 2011 Petr Skoda) from Moodle 2.4. */

defined('MOODLE_INTERNAL') || die;

require_once(dirname(__FILE__) . '/lib.php');
require_once(dirname(__FILE__) . '/CSS/CSSParser.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/book/lib.php');
require_once($CFG->dirroot . '/mod/book/locallib.php');

ini_set('max_execution_time', 600);
set_time_limit(600);

if (!function_exists('create_module')) {        // Moodle <= 2.4
    function create_module($data) {
        return NULL;
    }
}

/**
 * Create a new book module
 *
 * @param object $module
 * @param object $course
 * @param int $section
 * @param string $title
 */
function toolbook_importepub_add_book($module, $course, $section, $title = NULL) {
    $data = new stdClass();

    if ($title) {
        $data->name = substr($title, 0, 250);
    } else {
        $data->name = 'Untitled';
    }
    $data->numbering = 0;
    $data->customtitles = 1;
    $data->revision = 0;
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;

    $data->introeditor = array();
    $data->introeditor['text'] = '<p>' . htmlentities($data->name, ENT_COMPAT, 'UTF-8') . '</p>';
    $data->introeditor['format'] = 1;
    $data->introeditor['itemid'] = 0;   // FIXME

    $data->course = $course->id;
    $data->section = $section;

    $data->module = $module->id;
    $data->modulename = $module->name;
    $data->visible = TRUE;
    $data->visibleoncoursepage = TRUE;
    $data->groupmode = $course->groupmode;
    $data->groupingid = $course->defaultgroupingid;
    $data->groupmembersonly = 0;

    $data->completionexpected = 0;
    $data->availablefrom = 0;
    $data->availableuntil = 0;
    $data->showavailability = 0;
    $data->conditiongradegroup = array();
    $data->conditionfieldgroup = array();

    $data->completiongradeitemnumber = NULL;
    $data->availability = NULL;

    $data = create_module($data);
    if (!$data) {
        return NULL;
    }

    return $data;
}

/**
 * Update title of book module
 *
 * @param object $data
 * @param string $title
 */
function toolbook_importepub_update_book_title($data, $title) {
    $data->name = substr($title, 0, 250);
    $data->intro = '<p>' . htmlentities($title, ENT_COMPAT, 'UTF-8') . '</p>';
    $data->introformat = 1;
    book_update_instance($data, NULL);
}

/**
 * Create a new book module from an EPUB ebook
 *
 * @param stored_file $package EPUB file
 * @param object $course
 * @param int $section
 * @param object $settings
 * @param bool $verbose
 */
function toolbook_importepub_add_epub($package, $course, $section,
                                      $settings, $verbose = FALSE) {
    global $DB, $OUTPUT;

    $module = $DB->get_record('modules', array('name' => 'book'), '*',
                              MUST_EXIST);

    $data = toolbook_importepub_add_book($module, $course, $section);
    if (!$data) {
        echo $OUTPUT->notification(get_string('importing',
                                              'booktool_importhtml'),
                                   'notifyproblem');
        return;
    }

    // $context = context_course::instance($course->id);
    $context = context_module::instance($data->coursemodule);
    toolbook_importepub_unzip_files($package, $context);

    $title = toolbook_importepub_get_title($context);
    if ($title) {
        toolbook_importepub_update_book_title($data, $title);
        rebuild_course_cache($course->id);
        // update_module($data);
    }

    $chapterfiles = toolbook_importepub_get_chapter_files($package, $context);
    $chapternames = toolbook_importepub_get_chapter_names($context);
    $chapternames[0] = $title;

    $cm = get_coursemodule_from_id('book', $data->coursemodule, 0, FALSE,
                                   MUST_EXIST);
    $book = $DB->get_record('book', array('id' => $cm->instance), '*',
                            MUST_EXIST);

    echo $OUTPUT->notification(get_string('importing', 'booktool_importhtml'),
                               'notifysuccess');

    toolbook_importepub_import_chapters($package, $chapterfiles, $chapternames,
                                        $settings, $book, $context, $verbose);
    toolbook_importepub_delete_files($context);
}

/**
 * Create new book modules from an EPUB ebook, one new book per chapter
 *
 * @param stored_file $package EPUB file
 * @param object $course
 * @param int $section
 * @param object $settings
 * @param bool $verbose
 */
function toolbook_importepub_add_epub_chapters($package, $course, $section,
                                               $settings, $verbose = FALSE) {
    global $DB, $OUTPUT;

    $module = $DB->get_record('modules', array('name' => 'book'), '*',
                              MUST_EXIST);

    $data = toolbook_importepub_add_book($module, $course, $section);
    if (!$data) {
        echo $OUTPUT->notification(get_string('importing',
                                              'booktool_importhtml'),
                                   'notifyproblem');
        return;
    }

    $context = context_module::instance($data->coursemodule);
    toolbook_importepub_unzip_files($package, $context);

    $booktitle = toolbook_importepub_get_title($context);

    $chapterfiles = toolbook_importepub_get_chapter_files($package, $context);
    $chapternames = toolbook_importepub_get_chapter_names($context);

    $fs = get_file_storage();
    foreach ($chapterfiles as $chapterfile) {
        if (!$data) {
            $data = toolbook_importepub_add_book($module, $course, $section);
            $context = context_module::instance($data->coursemodule);

            $fs->move_area_files_to_new_context($oldcontext->id, $context->id,
                                                'mod_book', 'importepubtemp',
                                                0);
            //toolbook_importepub_unzip_files($package, $context);
        }

        $title = '';
        if (array_key_exists($chapterfile->pathname, $chapternames)) {
            $title = $chapternames[$chapterfile->pathname];
        } else {
            // $title = toolbook_importhtml_parse_title($htmlcontent, $chapterfile->pathname);
            $title = $chapterfile->pathname;
        }
        if ($title == '') {
            $title = $booktitle;
        }
        if ($title == '') {
            $title = '*';
        }
        $title = trim($title);

        toolbook_importepub_update_book_title($data, $title);
        rebuild_course_cache($course->id);

        $cm = get_coursemodule_from_id('book', $data->coursemodule, 0, FALSE,
                                       MUST_EXIST);
        $book = $DB->get_record('book', array('id' => $cm->instance), '*',
                                MUST_EXIST);

        echo $OUTPUT->notification(get_string('importing',
                                              'booktool_importhtml'),
                                   'notifysuccess');

        toolbook_importepub_import_chapters($package, array($chapterfile),
                                            $chapternames, $settings,
                                            $book, $context, $verbose);

        //toolbook_importepub_delete_files($context);
        $oldcontext = $context;
        $data = NULL;
    }

    toolbook_importepub_delete_files($context);
    rebuild_course_cache($course->id);
}

/**
 * Import chapters from an EPUB ebook into an existing book
 *
 * @param stored_file $package EPUB file
 * @param object $book
 * @param context_module $context
 * @param object $settings
 * @param bool $verbose
 */
function toolbook_importepub_import_epub($package, $book, $context,
                                         $settings, $verbose = FALSE) {
    global $OUTPUT;

    toolbook_importepub_unzip_files($package, $context);
    $chapterfiles = toolbook_importepub_get_chapter_files($package, $context);
    $chapternames = toolbook_importepub_get_chapter_names($context);

    echo $OUTPUT->notification(get_string('importing', 'booktool_importhtml'),
                               'notifysuccess');

    toolbook_importepub_import_chapters($package, $chapterfiles, $chapternames,
                                        $settings, $book, $context, $verbose);
    toolbook_importepub_delete_files($context);
}

/**
 * Unzip EPUB ebook
 *
 * @param stored_file $package EPUB file
 * @param context_module $context
 */
function toolbook_importepub_unzip_files($package, $context) {
    $packer = get_file_packer('application/zip');
    $found = FALSE;
    $basepath = '';
    foreach ($package->list_files($packer) as $file) {
        if (empty($file->pathname)) {
            continue;
        }
        if ($file->pathname == 'META-INF/container.xml') {
            $found = TRUE;
            break;
        } else if (preg_match("#^(.+/)?META-INF/container\\.xml$#",
                              $file->pathname, $matches)) {
            $found = TRUE;
            $basepath = $matches[1];
        }
    }
    if (!$found) {
        return FALSE;
    }

    toolbook_importepub_delete_files($context);
    $package->extract_to_storage($packer, $context->id, 'mod_book',
                                 'importepubtemp', 0, '/');

    if ($basepath) {
        // Move files to root
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_book',
                                     'importepubtemp', 0, 'id', FALSE);
        foreach ($files as $file) {
            if (preg_match("#" . $basepath . "(.+)$#",
                           $file->get_filepath() . $file->get_filename(),
                           $matches)) {
                $name = $matches[1];
                if ($name . "/" == $basepath) {
                    toolbook_importepub_delete_files($context);
                    return FALSE;
                }
                $file->rename('/' . dirname($name) . '/', basename($name));
            }
        }
    }
}

/**
 * Delete previously unzipped EPUB ebook
 *
 * @param context_module $context
 */
function toolbook_importepub_delete_files($context) {
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_book', 'importepubtemp', 0);
}

/**
 * Get OPF DOM and OPF root path from unzipped EPUB ebook
 *
 * @param context_module $context
 *
 * @return array with DOM document and string
 */
function toolbook_importepub_get_opf($context) {
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

    // Container
    $filehash = $fs->get_pathname_hash($context->id, 'mod_book',
                                       'importepubtemp', 0, '/META-INF/',
                                       'container.xml');
    $file = $fs->get_file_by_hash($filehash);
    if (!$file) {
        return FALSE;
    }
    $doc = new DOMDocument();
    if (!$doc->loadXML($file->get_content())) {
        return FALSE;
    }
    $items = $doc->getElementsByTagNameNS(CNT_NS, 'rootfile');
    if (!$items) {
        return FALSE;
    }
    $rootfile = $items->item(0)->getAttribute('full-path');
    list($opfroot, $opfname) = toolbook_importepub_get_path('', $rootfile, TRUE);

    // OPF file
    $filehash = $fs->get_pathname_hash($context->id, 'mod_book',
                                       'importepubtemp', 0,
                                       '/' . $opfroot, $opfname);
    $file = $fs->get_file_by_hash($filehash);
    if (!$file) {
        return FALSE;
    }
    $doc = new DOMDocument();
    if (!$doc->loadXML($file->get_content())) {
        return FALSE;
    }

    return array($doc, $opfroot);
}

/**
 * Get title from unzipped EPUB ebook
 *
 * @param context_module $context
 *
 * @return string or NULL
 */
function toolbook_importepub_get_title($context) {
    list($opf, $opfroot) = toolbook_importepub_get_opf($context);
    if ($opf) {
        $items = $opf->getElementsByTagName('title');
        if ($items) {
            return $items->item(0)->nodeValue;
        }
    }
    return NULL;
}

/**
 * Get chapter names from unzipped EPUB ebook
 *
 * @param context_module $context
 *
 * @return array mapping filepath => chapter name
 */
function toolbook_importepub_get_chapter_names($context) {
    $chapternames = array();

    list($opf, $opfroot) = toolbook_importepub_get_opf($context);
    if (!$opf) {
        return $chapternames;
    }

    // Find nav document (EPUB 3)
    $nav = NULL;
    $items = $opf->getElementsByTagNameNS(OPF_NS, 'item');
    foreach ($items as $item) {
        if ($item->hasAttribute('properties') && $item->hasAttribute('href')
            && ($item->getAttribute('properties') == 'nav')) {
            $nav = $item->getAttribute('href');
        }
    }

    if ($nav) {
        $fs = get_file_storage();

        // Container
        $filehash = $fs->get_pathname_hash($context->id, 'mod_book',
                                           'importepubtemp', 0, '/',
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

                // Find links
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

    // Find ncx document (EPUB 2)
    $ncx = NULL;
    $el = $opf->getElementsByTagNameNS(OPF_NS, 'spine');
    if ($el and $el->item(0)->hasAttribute('toc')) {
        $ncxid = $el->item(0)->getAttribute('toc');

        $el = NULL;
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

        // Container
        $filehash = $fs->get_pathname_hash($context->id, 'mod_book',
                                           'importepubtemp', 0, '/',
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

                // Find links
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
 * Returns all the html files from an EPUB ebook
 *
 * @param stored_file $package EPUB file to be processed
 * @param context_module $context
 *
 * @return array the html files found in the EPUB ebook
 */
function toolbook_importepub_get_chapter_files($package, $context) {
    $chapterfiles = array();

    list($opf, $opfroot) = toolbook_importepub_get_opf($context);
    if (!$opf) {
        return $chapterfiles;
    }

    // Manifest
    $manifest = array();
    foreach ($opf->getElementsByTagNameNS(OPF_NS, 'item') as $item) {
        if ($item->hasAttribute('id') and $item->hasAttribute('href') and
            $item->hasAttribute('media-type')) {
            $manifest[$item->getAttribute('id')] =
                array('href' => $item->getAttribute('href'),
                      'mediatype' => $item->getAttribute('media-type'));
        }
    }

    // Spine
    foreach ($opf->getElementsByTagNameNS(OPF_NS, 'itemref') as $item) {
        if ($item->hasAttribute('idref')) {
            // Maybe exclude if $item->getAttribute('linear') == 'no'?
            $it = $manifest[$item->getAttribute('idref')];
            if ($it['mediatype'] == 'application/xhtml+xml' or
                $it['mediatype'] == 'text/html') {
                //$filepath = $opfroot . $it['href'];
                $filepath = toolbook_importepub_get_path($opfroot, $it['href']);
                $chapterfile = new stdClass();
                $chapterfile->pathname = $filepath;
                $chapterfiles[] = $chapterfile;
            }
        }
    }

    return $chapterfiles;
}

function toolbook_importepub_parse_styles($doc, $basepath, $chap_id, $context,
                                          $settings) {
    $head = $doc->getElementsByTagName('head');
    if (!$head) {
        return NULL;
    }

    $dom = new DOMDocument();
    $body = $dom->createElement('body');
    $dom->appendChild($body);
    $modified = FALSE;

    foreach ($head[0]->childNodes as $el) {
        if ($el->localName == 'link' &&
            $el->hasAttribute('href') &&
            $el->hasAttribute('rel') &&
            $el->getAttribute('rel') == 'stylesheet') {
            $href = $el->getAttribute('href');
            $url = toolbook_importepub_update_link($href, $basepath,
                                                   $chap_id, $context,
                                                   TRUE, $settings);
            if (!$url) {
                $url = $href;
            }

            $nel = $dom->importNode($el, TRUE);
            $nel->setAttribute('href', $url);
            $body->appendChild($nel);
            $modified = TRUE;
        } else if ($el->localName == 'style') {
            $nel = $dom->importNode($el, TRUE);
            $nel->nodeValue = toolbook_importepub_get_css($nel->nodeValue,
                                                          $settings);
            $body->appendChild($nel);
            $modified = TRUE;
        }
    }

    if (!$modified) {
        return NULL;
    }
    return $body;
}

/**
 * Return normalized full path, or path and filename separated.
 * Path is without leading '/', but with ending '/', unless it is empty.
 */
function toolbook_importepub_get_path($basepath, $filepath, $split = FALSE) {
    $temp = rawurldecode($filepath);
    $fpath = $basepath . '/' . $temp;
    $fpath = str_replace('//', '/', $fpath);
    $fpath = ltrim($fpath, './');
    $fpath = ltrim($fpath, '/');

    $cnt = substr_count($fpath, '..');
    for ($i = 0; $i < $cnt; $i++) {
        $fpath = preg_replace('#[^/]+/\.\./#', '', $fpath, 1);
    }

    $fpath = clean_param($fpath, PARAM_PATH);

    if (!$split) {
        return $fpath;
    }

    $path = dirname($fpath);
    if ($path == '.' || $path == '/') {
        $path = '';
    } else if ($path != '' && substr($path, -1) != '/') {
        $path .= '/';
    }
    $name = basename($fpath);
    return array($path, $name);
}

/**
 * Convert some HTML content to UTF-8, getting original encoding from HTML head
 *
 * @param string $html html content to convert
 * @return string html content converted to utf8
 */
function toolbook_importepub_fix_encoding($html) {
    if (preg_match('/<head[^>]*>(.+)<\/head>/is', $html, $matches)) {
        $head = $matches[1];
        if (preg_match('/charset=([^"]+)/is', $head, $matches)) {
            $enc = $matches[1];
            return core_text::convert($html, $enc, 'utf-8');
        }
    }
    return iconv('UTF-8', 'UTF-8//IGNORE', $html);
}

/**
 * Extract the contents of the body
 *
 * @param string $body the body element node
 * @return string the contents of the body
 */
function toolbook_importepub_get_body($body) {
    //$html = $root->ownerDocument->saveXML($body);
    $html = $body->ownerDocument->saveHTML($body);
    if (preg_match('/<body[^>]*>(.+)<\/body>/is', $html, $matches)) {
        $html = $matches[1];
    }
    // If saveXML is used, CDATA tags must be removed
    //$html = str_replace('<![CDATA[', '', $html);
    //$html = str_replace(']]>', '', $html);
    return $html;
}

/**
 * Store linked files (images, audio, video etc)
 * and return new URL.
 */
function toolbook_importepub_update_link($src, $basepath, $chap_id, $context,
                                         $stylesheet = FALSE, $settings = NULL) {
    $url = parse_url($src);
    if (array_key_exists('scheme', $url)) {
        // Not a relative link
        return '';
    }

    list($path, $name) = toolbook_importepub_get_path($basepath, $src, TRUE);

    $fs = get_file_storage();
    $filehash = $fs->get_pathname_hash($context->id, 'mod_book',
                                       'importepubtemp', 0, '/' . $path, $name);
    $file = $fs->get_file_by_hash($filehash);
    if (!$file) {
        // Cannot find the file
        return '';
    }

    $filehash = $fs->get_pathname_hash($context->id, 'mod_book', 'chapter',
                                       $chap_id, '/' . $path, $name);
    $oldfile = $fs->get_file_by_hash($filehash);
    if (!$oldfile) {
        $filerecord = array('contextid' => $context->id,
                            'component' => 'mod_book',
                            'filearea' => 'chapter',
                            'itemid' => $chap_id);

        $text = '';
        if ($stylesheet) {
            try {
                $text = toolbook_importepub_get_css($file->get_content(),
                                                    $settings);
            } catch (Exception $e) {
                // FIXME Maybe ignore the CSS file if it cannot be read
                //return '';
            }
        }

        if ($text) {
            $filerecord['filepath'] = '/' . $path;
            $filerecord['filename'] = $name;
            $fs->create_file_from_string($filerecord, $text);
        } else {
            $fs->create_file_from_storedfile($filerecord, $file);
        }
    }

    return '@@PLUGINFILE@@/' . $path . $name;
}

/**
 * Import chapters
 *
 * @param stored_file $package
 * @param array $chapterfiles
 * @param array $chapternames
 * @param array $settings
 * @param stdClass $book
 * @param context_module $context
 * @param bool $verbose
 */
function toolbook_importepub_import_chapters($package, $chapterfiles,
                                             $chapternames, $settings,
                                             $book, $context, $verbose = TRUE) {
    global $DB, $OUTPUT;

    //toolbook_importepub_unzip_files($package, $context);

    if ($verbose) {
        echo $OUTPUT->notification(get_string('importing',
                                              'booktool_importhtml'),
                                   'notifysuccess');
    }

    $chapters = array();
    $chaprefs = array();

    $fs = get_file_storage();
    foreach ($chapterfiles as $chapterfile) {
        $filehash = $fs->get_pathname_hash($context->id, 'mod_book',
                                           'importepubtemp', 0, '/',
                                           $chapterfile->pathname);
        $file = $fs->get_file_by_hash($filehash);
        if (!$file) {
            continue;
        }

        $html = toolbook_importepub_fix_encoding($file->get_content());

        // Handle self closing a-tags
        $html = preg_replace("/<a ([^>]+)\\/>/", "<a $1>&#8203;</a>", $html);

        $doc = new DOMDocument();
        @$doc->loadHTML($html);

        $chapref = new stdClass();
        $chapref->chaps = array();

        $chaps = toolbook_importepub_split($doc, $settings->tag,
                                           $settings->classes);

        $ldoc = new DOMDocument();
        $header = mb_convert_encoding($settings->header,
                                      'HTML-ENTITIES', 'UTF-8');
        @$ldoc->loadHTML('<body>' . $header . '</body>');
        $header = toolbook_importepub_get_body($ldoc->documentElement);
        $footer = mb_convert_encoding($settings->footer,
                                      'HTML-ENTITIES', 'UTF-8');
        @$ldoc->loadHTML('<body>' . $footer . '</body>');
        $footer = toolbook_importepub_get_body($ldoc->documentElement);

        foreach ($chaps as $chap) {
            $chapter = new stdClass();
            $chapter->bookid = $book->id;
            $chapter->pagenum = $DB->get_field_sql('SELECT MAX(pagenum) FROM {book_chapters} WHERE bookid = ?', array($book->id)) + 1;
            $chapter->importsrc = '/' . $chapterfile->pathname;

            $chapter->content = '<div class="lucimoo">' . $header .
                                toolbook_importepub_get_body($chap->body) .
                                $footer . '</div>';

            if ($chap->subchapter == 0 &&
                array_key_exists($chapterfile->pathname, $chapternames)) {
                $chapter->title = $chapternames[$chapterfile->pathname];
            } else {
                $chapter->title = $chap->title;
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
            $chapter->hidden = 0;
            $chapter->timecreated = time();
            $chapter->timemodified = $chapter->timecreated;
            $chapter->subchapter = $chap->subchapter;
            $chapter->id = $DB->insert_record('book_chapters', $chapter);
            $chapters[$chapter->id] = $chapter;

            $ch = new stdClass();
            $ch->content = $chapter->content;
            $ch->id = $chapter->id;
            list($temp, $tempb) = toolbook_importepub_get_path('', $chapterfile->pathname, TRUE);
            $ch->path = $temp;
            $chapref->chaps[] = $ch;

            // add_to_log($book->course, 'book', 'add chapter', 'view.php?id='.$context->instanceid.'&chapterid='.$chapter->id, $chapter->id, $context->instanceid);
        }

        if ($settings->enablestyles && count($chapref->chaps) > 0) {
            //$chapref->stylebody = toolbook_importepub_parse_styles($doc, $chapref->chaps[0]->path, $chapref->chaps[0]->id, $context, $settings);

            // Since the style URLs uses @@PLUGINFILE@@ every style file
            // must be copied to every chapter, instead of just using one copy
            foreach ($chapref->chaps as $chap) {
                $chapref->stylebody = toolbook_importepub_parse_styles($doc, $chap->path, $chap->id, $context, $settings);
            }
        } else {
            $chapref->stylebody = NULL;
        }

        $chaprefs[$chapterfile->pathname] = $chapref;
    }

    // Update links
    foreach ($chaprefs as $chapref) {
        foreach ($chapref->chaps as $chap) {
            $modified = FALSE;
            $doc = new DOMDocument();
            //$doc->loadXML($chap->content);
            //$body = $doc->documentElement;

            @$doc->loadHTML('<?xml encoding="UTF-8"?>' . $chap->content);
            $body = $doc->getElementsByTagName("body");
            if (!$body) {
                continue;
            }
            $body = $body[0];

            if (!($body || $body->firstChild)) {
                continue;
            }

            // Update anchor links
            foreach ($doc->getElementsByTagName("a") as $anchor) {
                if (!$anchor->hasAttribute("href")) {
                    continue;
                }
                $href = $anchor->getAttribute("href");
                $url = parse_url($href);
                if (array_key_exists('scheme', $url)) {
                    // Not a relative link
                    continue;
                }
                $targetpath = '';
                if (array_key_exists('path', $url)) {
                    $targetpath = $url['path'];
                }
                $targetid = '';
                if (array_key_exists('fragment', $url)) {
                    $targetid = $url['fragment'];
                }
                $target = NULL;
                if (!$targetpath && $targetid) {
                    $target = $chapref;
                } else if (array_key_exists($chap->path . $targetpath,
                                            $chaprefs)) {
                    $target = $chaprefs[$chap->path . $targetpath];
                }
                if ($target) {
                    // Find link target chapter
                    foreach ($target->chaps as $targetchap) {
                        if (preg_match("/ id=['\"]" . $targetid . "['\"]/",
                                       $targetchap->content)) {
                            if ($targetchap->id != $chap->id) {
                                $nurl = new moodle_url('/mod/book/view.php', array('id' => $context->instanceid, 'chapterid' => $targetchap->id), $targetid);
                                $anchor->setAttribute('href', $nurl->out(FALSE));
                                $modified = TRUE;
                            }
                        }
                    }
                }
            }

            # Update link[@rel='stylesheet'] links
            foreach ($doc->getElementsByTagName("link") as $obj) {
                if (!($obj->hasAttribute("href") &&
                      $obj->hasAttribute("rel") &&
                      $obj->hasAttribute("rel") == "stylesheet")) {
                    continue;
                }
                $href = $obj->getAttribute("href");
                $url = toolbook_importepub_update_link($href, $chap->path,
                                                       $chap->id, $context,
                                                       TRUE, $settings);
                if ($url) {
                    $obj->setAttribute("href", $url);
                    $modified = TRUE;
                }
            }

            # Update object links
            # FIXME Should maybe handle codebase attribute?
            foreach ($doc->getElementsByTagName("object") as $obj) {
                if (!$obj->hasAttribute("data")) {
                    continue;
                }
                $data = $obj->getAttribute("data");
                $url = toolbook_importepub_update_link($data, $chap->path,
                                                       $chap->id, $context);
                if ($url) {
                    $obj->setAttribute("data", $url);
                    $modified = TRUE;
                }
            }

            # Update img links
            foreach ($doc->getElementsByTagName("img") as $obj) {
                if (!$obj->hasAttribute("src")) {
                    continue;
                }
                $src = $obj->getAttribute("src");
                $url = toolbook_importepub_update_link($src, $chap->path,
                                                       $chap->id, $context);
                if ($url) {
                    $obj->setAttribute("src", $url);
                    $modified = TRUE;
                }
            }

            # Update video links
            foreach ($doc->getElementsByTagName("video") as $obj) {
                if (!$obj->hasAttribute("src")) {
                    continue;
                }
                $src = $obj->getAttribute("src");
                $url = toolbook_importepub_update_link($src, $chap->path,
                                                       $chap->id, $context);
                if ($url) {
                    $obj->setAttribute("src", $url);
                    $modified = TRUE;
                }
            }

            # Update audio links
            foreach ($doc->getElementsByTagName("audio") as $obj) {
                if (!$obj->hasAttribute("src")) {
                    continue;
                }
                $src = $obj->getAttribute("src");
                $url = toolbook_importepub_update_link($src, $chap->path,
                                                       $chap->id, $context);
                if ($url) {
                    $obj->setAttribute("src", $url);
                    $modified = TRUE;
                }
            }

            # Update source links
            foreach ($doc->getElementsByTagName("source") as $obj) {
                if (!$obj->hasAttribute("src")) {
                    continue;
                }
                $src = $obj->getAttribute("src");
                $url = toolbook_importepub_update_link($src, $chap->path,
                                                       $chap->id, $context);
                if ($url) {
                    $obj->setAttribute("src", $url);
                    $modified = TRUE;
                }
            }

            # Update embed links
            foreach ($doc->getElementsByTagName("embed") as $obj) {
                if (!$obj->hasAttribute("src")) {
                    continue;
                }
                $src = $obj->getAttribute("src");
                $url = toolbook_importepub_update_link($src, $chap->path,
                                                       $chap->id, $context);
                if ($url) {
                    $obj->setAttribute("src", $url);
                    $modified = TRUE;
                }
            }

            // Include stylesheets
            if ($chapref->stylebody) {
                foreach ($chapref->stylebody->childNodes as $el) {
                    $nel = $doc->importNode($el, TRUE);
                    $body->insertBefore($nel, $body->firstChild);
                    //$doc->documentElement->insertBefore($nel, $doc->documentElement->firstChild);
                }
                $modified = TRUE;
            }

            if ($modified) {
                // Store modified chapter content
                $DB->set_field('book_chapters', 'content', toolbook_importepub_get_body($doc->documentElement), array('id' => $chap->id));
            }
        }
    }

    if ($verbose) {
        echo $OUTPUT->notification(get_string('relinking',
                                              'booktool_importhtml'),
                                   'notifysuccess');
    }

    // add_to_log($book->course, 'course', 'update mod', '../mod/book/view.php?id='.$context->instanceid, 'book '.$book->id);

    //toolbook_importepub_delete_files($context);

    // update the revision flag - this takes a long time, better to refetch the current value
    $book = $DB->get_record('book', array('id' => $book->id));
    $DB->set_field('book', 'revision', $book->revision + 1, array('id' => $book->id));
}

function toolbook_importepub_get_css($input, $settings) {
    $cssparser = new Sabberworm\CSS\Parser($input);
    $css = $cssparser->parse();
    foreach ($css->getAllDeclarationBlocks() as $block) {
        foreach ($block->getSelectors() as $selector) {
            if ($selector->getSelector() == 'body') {
                $selector->setSelector('div.lucimoo');
            } else if ($selector->getSelector() == 'html') {
                $selector->setSelector('div.lucimoo');
            } else {
                $selector->setSelector('div.lucimoo ' .
                                       $selector->getSelector());
            }
        }
    }

    if ($settings->preventsmallfonts) {
        foreach ($css->getAllRuleSets() as $ruleset) {
            foreach ($ruleset->getRules() as $rule) {
                if ($rule->getRule() == 'font-size') {
                    $value = $rule->getValue();
                    if ($value instanceof Sabberworm\CSS\Value\Size) {
                        if ($value->getUnit() == 'em' ||
                            $value->getUnit() == 'rem') {
                            if (floatval($value->getSize()) < 1) {
                                $value->setSize(1);
                            }
                        } else if ($value->getUnit() == '%') {
                            if (floatval($value->getSize()) < 100) {
                                $value->setSize(100);
                            }
                        }
                    }
                //} else if ($rule->getRule() == 'font') {
                /* } else if ($rule->getRule() == 'line-height') { */
                /*     $value = $rule->getValue(); */
                /*     if ($value instanceof Sabberworm\CSS\Value\Size) { */
                /*         if ($value->getUnit() == '') { */
                /*             if (floatval($value->getSize()) < 1.2) { */
                /*                 $rule->setValue('normal'); */
                /*             } */
                /*         } */
                /*     } */
                }
            }
        }
    }

    if ($settings->ignorefontfamily) {
        foreach ($css->getAllRuleSets() as $ruleset) {
            if ($ruleset instanceof Sabberworm\CSS\RuleSet\AtRuleSet) {
                continue;
            }
            foreach ($ruleset->getRules() as $rule) {
                if ($rule->getRule() == 'font-family') {
                    $rule->setValue('inherit');
                }
            }
        }
    }

    return $css->__toString();
}

/*
 * Split $body in two parts before $element.
 */
function toolbook_importepub_split_body($body, $element) {
    if ($element->isSameNode($body)) {
        return $body;
    }

    $parent = $element->parentNode;

    if ((!$parent->firstChild->isSameNode($element)) &&
        $parent->firstChild->isSameNode($element->previousSibling)) {
        // Remove if only whitespace
        if ($parent->firstChild->nodeType == XML_TEXT_NODE &&
            preg_match("/^\\s$/", $parent->firstChild->textContent)) {
            $parent->removeChild($parent->firstChild);
        }
    }

    if (!$parent->firstChild->isSameNode($element)) {
        // Split parent element
        $parentCopy = $parent->cloneNode(FALSE);
        $parent->parentNode->insertBefore($parentCopy, $parent);
        while (!$parent->firstChild->isSameNode($element)) {
            $parentCopy->appendChild($parent->firstChild);
        }
    }

    return toolbook_importepub_split_body($body, $parent);
}

function toolbook_importepub_get_nodes($root) {
    if ($root->hasChildNodes()) {
        foreach ($root->childNodes as $node) {
            yield $node;
            toolbook_importepub_get_nodes($node);
        }
    }
}

function toolbook_importepub_body_is_empty($root) {
    foreach (toolbook_importepub_get_nodes($root) as $node) {
        if ($node->nodeType == XML_TEXT_NODE) {
            if (!preg_match('/^\\s$/', $node)) {
                return FALSE;
            }
        } elseif ($node->nodeType == XML_ELEMENT_NODE) {
            if ($node->localName == 'img' ||
                $node->localName == 'object') {
                return FALSE;
            }
        }
    }
    return TRUE;
}

function toolbook_importepub_split($doc, $tag, $classes) {
    $body = $doc->getElementsByTagName("body");
    if (!$body) {
        return array();
    }
    $body = $body->item(0);

    // Find split points
    $chapters = array();
    foreach ($body->getElementsByTagName($tag) as $chapter) {
        if (count($classes) > 0) {
            if ($chapter->hasAttribute('class')) {
                $names = preg_split('/\s+/', $chapter->getAttribute('class'),
                                    -1, PREG_SPLIT_NO_EMPTY);
                foreach ($names as $name) {
                    if (in_array($name, $classes)) {
                        $chapters[] = $chapter;
                        break;
                    }
                }
            }
        } else {
            $chapters[] = $chapter;
        }
    }

    // Get headings
    $headings = array();
    foreach ($chapters as $chapter) {
        $headings[] = trim($chapter->textContent);
    }

    // Perform splits
    foreach ($chapters as $chapter) {
        toolbook_importepub_split_body($body, $chapter);
    }

    /*
    $body = $doc->getElementsByTagName("body")->item(0);
    if (toolbook_importepub_body_is_empty($body)) {
        $body->parentNode->removeChild($body);
    }
    */

    // Insert empty main chapter if the document only contains subchapters
    $bodies = $doc->getElementsByTagName("body");
    if ($bodies->length == count($headings)) {
        $body = $bodies->item(0);
        $body->parantNode->insertBefore($doc->createElement("body"), $body);
    }

    $result = array();
    $bodies = $doc->getElementsByTagName("body");

    $chap = new stdClass();
    $chap->body = $bodies->item(0);
    $chap->title = "";
    $chap->subchapter = 0;
    $result[] = $chap;

    $i = 1;
    foreach ($headings as $heading) {
        $chap = new stdClass();
        $chap->body = $bodies->item($i);
        $chap->title = $heading;
        $chap->subchapter = 1;
        $result[] = $chap;
        $i += 1;
    }
    return $result;
}
