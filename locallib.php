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
 * @copyright  2013-2014 Mikael Ylikoski
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* This file contains code based on mod/book/tool/importhtml/locallib.php
 * (copyright 2011 Petr Skoda) from Moodle 2.4. */

defined('MOODLE_INTERNAL') || die;

require_once(dirname(__FILE__).'/lib.php');
require_once(dirname(__FILE__).'/CSS/CSSParser.php');
require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->dirroot.'/mod/book/lib.php');
require_once($CFG->dirroot.'/mod/book/locallib.php');
require_once($CFG->dirroot.'/mod/book/tool/importhtml/locallib.php');

if (!function_exists('create_module')) {        // Moodle <= 2.4
    function create_module($data) {
        return null;
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
function toolbook_importepub_add_book($module, $course, $section, $title = null) {
    $data = new stdClass();

    if ($title) {
        $data->name = substr($title, 0, 250);
    } else {
        $data->name = 'Untitled';
    }
    $data->numbering = 0;
    $data->customtitles = 0;
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
    $data->visible = true;
    $data->groupmode = $course->groupmode;
    $data->groupingid = $course->defaultgroupingid;
    $data->groupmembersonly = 0;

    $data->completionexpected = 0;
    $data->availablefrom = 0;
    $data->availableuntil = 0;
    $data->showavailability = 0;
    $data->conditiongradegroup = array();
    $data->conditionfieldgroup = array();

    $data = create_module($data);
    if (!$data) {
        return null;
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
    book_update_instance($data, null);
}

/**
 * Create a new book module from an EPUB ebook
 *
 * @param stored_file $package EPUB file
 * @param object $course
 * @param int $section
 * @param bool $enablestyles
 * @param bool $verbose
 */
function toolbook_importepub_add_epub($package, $course, $section,
                                      $enablestyles, $verbose = false) {
    global $DB, $OUTPUT;

    $module = $DB->get_record('modules', array('name' => 'book'), '*',
                              MUST_EXIST);

    $data = toolbook_importepub_add_book($module, $course, $section);
    if (!$data) {
        echo $OUTPUT->notification(get_string('importing', 'booktool_importhtml'),
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
    toolbook_importepub_delete_files($context);

    $cm = get_coursemodule_from_id('book', $data->coursemodule, 0, false,
                                   MUST_EXIST);
    $book = $DB->get_record('book', array('id' => $cm->instance), '*',
                            MUST_EXIST);
    echo $OUTPUT->notification(get_string('importing', 'booktool_importhtml'),
                               'notifysuccess');

    return toolbook_importepub_import_chapters($package, 2, $chapterfiles,
                                               $chapternames, $enablestyles,
                                               $book, $context, $verbose);
}

/**
 * Create new book modules from an EPUB ebook, one new book per chapter
 *
 * @param stored_file $package EPUB file
 * @param object $course
 * @param int $section
 * @param bool $verbose
 */
function toolbook_importepub_add_epub_chapters($package, $course, $section,
                                               $enablestyles,
                                               $verbose = false) {
    global $DB, $OUTPUT;

    $module = $DB->get_record('modules', array('name' => 'book'), '*',
                              MUST_EXIST);

    $data = toolbook_importepub_add_book($module, $course, $section);
    if (!$data) {
        echo $OUTPUT->notification(get_string('importing', 'booktool_importhtml'),
                                   'notifyproblem');
        return;
    }

    $context = context_module::instance($data->coursemodule);
    toolbook_importepub_unzip_files($package, $context);
    $chapterfiles = toolbook_importepub_get_chapter_files($package, $context);
    $chapternames = toolbook_importepub_get_chapter_names($context);
    toolbook_importepub_delete_files($context);

    foreach ($chapterfiles as $chapterfile) {
        $title = '';
        if (array_key_exists($chapterfile->pathname, $chapternames)) {
            $title = $chapternames[$chapterfile->pathname];
        } else {
            // $title = toolbook_importhtml_parse_title($htmlcontent, $chapterfile->pathname);
            $title = $chapterfile->pathname;
        }
        $title = trim($title);
        if ($title == '') {
            $title = '*';
        }

        if ($data) {
            toolbook_importepub_update_book_title($data, $title);
            rebuild_course_cache($course->id);
        } else {
            $data = toolbook_importepub_add_book($module, $course, $section,
                                                 $title);
        }

        $cm = get_coursemodule_from_id('book', $data->coursemodule, 0, false,
                                       MUST_EXIST);
        $book = $DB->get_record('book', array('id' => $cm->instance), '*',
                                MUST_EXIST);
        $context = context_module::instance($data->coursemodule);
        echo $OUTPUT->notification(get_string('importing',
                                              'booktool_importhtml'),
                                   'notifysuccess');
        toolbook_importepub_import_chapters($package, 2, array($chapterfile),
                                            $chapternames, $enablestyles,
                                            $book, $context, $verbose);
        $data = null;
    }
    rebuild_course_cache($course->id);
}

/**
 * Import HTML pages from an EPUB ebook
 *
 * @param stored_file $package EPUB file
 * @param stdClass $book
 * @param context_module $context
 * @param bool $verbose
 */
function toolbook_importepub_import_epub($package, $book, $context,
                                         $enablestyles, $verbose = false) {
    global $OUTPUT;

    toolbook_importepub_unzip_files($package, $context);
    $chapterfiles = toolbook_importepub_get_chapter_files($package, $context);
    $chapternames = toolbook_importepub_get_chapter_names($context);
    toolbook_importepub_delete_files($context);
    echo $OUTPUT->notification(get_string('importing', 'booktool_importhtml'),
                               'notifysuccess');
    return toolbook_importepub_import_chapters($package, 2, $chapterfiles,
                                               $chapternames, $enablestyles,
                                               $book, $context, $verbose);
}

/**
 * Unzip EPUB ebook
 *
 * @param stored_file $package EPUB file
 * @param context_module $context
 */
function toolbook_importepub_unzip_files($package, $context) {
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

    toolbook_importepub_delete_files($context);
    $package->extract_to_storage($packer, $context->id, 'mod_book',
                                 'importhtmltemp', 0, '/');
}

/**
 * Delete previously unzipped EPUB ebook
 *
 * @param context_module $context
 */
function toolbook_importepub_delete_files($context) {
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_book', 'importhtmltemp', 0);
}

/**
 * Get OPF DOM and OPF root path from previously unzipped EPUB ebook
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
                                       'importhtmltemp', 0, '/',
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

    // OPF file
    $filehash = $fs->get_pathname_hash($context->id, 'mod_book',
                                       'importhtmltemp', 0, '/', $rootfile);
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
 * Get title from previously unzipped EPUB ebook
 *
 * @param context_module $context
 *
 * @return string or null
 */
function toolbook_importepub_get_title($context) {
    list($opf, $opfroot) = toolbook_importepub_get_opf($context);
    if ($opf) {
        $items = $opf->getElementsByTagName('title');
        if ($items) {
            return $items->item(0)->nodeValue;
        }
    }
    return null;
}

/**
 * Get chapter names from previously unzipped EPUB ebook
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

        // Container
        $filehash = $fs->get_pathname_hash($context->id, 'mod_book',
                                           'importhtmltemp', 0, '/',
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

        // Container
        $filehash = $fs->get_pathname_hash($context->id, 'mod_book',
                                           'importhtmltemp', 0, '/',
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
    $items = $opf->getElementsByTagNameNS(OPF_NS, 'item');
    foreach ($items as $item) {
        if ($item->hasAttribute('id') and $item->hasAttribute('href') and
            $item->hasAttribute('media-type')) {
            $manifest[$item->getAttribute('id')] =
                array('href' => $item->getAttribute('href'),
                      'mediatype' => $item->getAttribute('media-type'));
        }
    }

    // Spine
    $items = $opf->getElementsByTagNameNS(OPF_NS, 'itemref');
    foreach ($items as $item) {
        if ($item->hasAttribute('idref')) {
            // Maybe exclude if $item->getAttribute('linear') == 'no'?
            $it = $manifest[$item->getAttribute('idref')];
            if ($it['mediatype'] == 'application/xhtml+xml' or
                $it['mediatype'] == 'text/html') {
                $filepath = $opfroot . $it['href'];
                // FIXME $filepath should be normalized (i.e. '/..' removed)
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
 * @param bool $enablestyles
 * @param stdClass $book
 * @param context_module $context
 * @param bool $verbose
 */
function toolbook_importepub_import_chapters($package, $type, $chapterfiles,
                                             $chapternames, $enablestyles,
                                             $book, $context, $verbose = true) {
    global $DB, $OUTPUT;

    $fs = get_file_storage();
    $packer = get_file_packer('application/zip');
    $fs->delete_area_files($context->id, 'mod_book', 'importhtmltemp', 0);
    $package->extract_to_storage($packer, $context->id, 'mod_book', 'importhtmltemp', 0, '/');
    // $datafiles = $fs->get_area_files($context->id, 'mod_book', 'importhtmltemp', 0, 'id', false);
    // echo "<pre>";p(var_export($datafiles, true));

    $chapters = array();

    if ($verbose) {
        echo $OUTPUT->notification(get_string('importing', 'booktool_importhtml'), 'notifysuccess');
    }
    if ($type == 0) {
        $chapterfile = reset($chapterfiles);
        if ($file = $fs->get_file_by_hash("$context->id/mod_book/importhtmltemp/0/$chapterfile->pathname")) {
            $htmlcontent = toolbook_importhtml_fix_encoding($file->get_content());
            $htmlchapters = toolbook_importhtml_parse_headings(toolbook_importhtml_parse_body($htmlcontent));
            // TODO: process h1 as main chapter and h2 as subchapters
        }
    } else {
        foreach ($chapterfiles as $chapterfile) {
            if ($file = $fs->get_file_by_hash(sha1("/$context->id/mod_book/importhtmltemp/0/$chapterfile->pathname"))) {
                $chapter = new stdClass();
                $htmlcontent = toolbook_importhtml_fix_encoding($file->get_content());

                $chapter->bookid        = $book->id;
                $chapter->pagenum       = $DB->get_field_sql('SELECT MAX(pagenum) FROM {book_chapters} WHERE bookid = ?', array($book->id)) + 1;
                $chapter->importsrc     = '/'.$chapterfile->pathname;
                $chapter->content       = '';
                if ($enablestyles) {
                    $chapter->content   .= toolbook_importhtml_parse_styles($htmlcontent);
                }
                $chapter->content       .= '<div class="lucimoo">';
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
                if (preg_match('/_sub(\/|\.htm)/i', $chapter->importsrc)) { // If filename or directory ends with *_sub treat as subchapters
                    $chapter->subchapter = 1;
                } else {
                    $chapter->subchapter = 0;
                }

                $chapter->id = $DB->insert_record('book_chapters', $chapter);
                $chapters[$chapter->id] = $chapter;

                // add_to_log($book->course, 'book', 'add chapter', 'view.php?id='.$context->instanceid.'&chapterid='.$chapter->id, $chapter->id, $context->instanceid);
            }
        }
    }

    if ($verbose) {
        echo $OUTPUT->notification(get_string('relinking', 'booktool_importhtml'), 'notifysuccess');
    }
    $allchapters = $DB->get_records('book_chapters', array('bookid' => $book->id), 'pagenum');
    foreach ($chapters as $chapter) {
        // find references to all files and copy them + relink them
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
                    // skip linked html files, we will try chapter relinking later
                    foreach ($allchapters as $target) {
                        if ($target->importsrc === $filepath) {
                            continue 2;
                        }
                    }
                }

                if ($file = $fs->get_file_by_hash(sha1("/$context->id/mod_book/importhtmltemp/0$filepath"))) {
                    if (!$oldfile = $fs->get_file_by_hash(sha1("/$context->id/mod_book/chapter/$chapter->id$filepath"))) {
                        $text = '';
                        if (strtolower(substr($filepath, -4)) == '.css') {
                            try {
                                $text = toolbook_importepub_get_css($file);
                            } catch (Exception $e) {
                                // Just ignore the CSS file if it cannot be read
                            }
                        }
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

    // Relink relative HTML links
    $allchapters = $DB->get_records('book_chapters', array('bookid' => $book->id), 'pagenum');
    foreach ($allchapters as $chapter) {
        $newcontent = $chapter->content;
        $matches = null;
        if (preg_match_all('/(href)\s*=\s*[\'"]([^\'"#]+)(#([^\'"]*))?[\'"]/i',
                           $chapter->content, $matches)) {
            foreach ($matches[0] as $i => $match) {
                if (strpos($matches[2][$i], ':') !== false or strpos($matches[2][$i], '@') !== false) {
                    // it is either absolute or pluginfile link
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

    // add_to_log($book->course, 'course', 'update mod', '../mod/book/view.php?id='.$context->instanceid, 'book '.$book->id);
    $fs->delete_area_files($context->id, 'mod_book', 'importhtmltemp', 0);

    // update the revision flag - this takes a long time, better to refetch the current value
    $book = $DB->get_record('book', array('id' => $book->id));
    $DB->set_field('book', 'revision', $book->revision + 1, array('id' => $book->id));
}

function toolbook_importepub_get_css($file) {
    $cssparser = new Sabberworm\CSS\Parser($file->get_content());
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
    return $css->__toString();
}
