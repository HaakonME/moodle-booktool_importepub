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
 * Import Microsoft Word file library.
 *
 * @package    booktool
 * @subpackage wordimport
 * @copyright  2015 Eoin Campbell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* This file contains code based on mod/book/tool/print/lib.php
 * (copyright 2004-2011 Petr Skoda) from Moodle 2.4. */

defined('MOODLE_INTERNAL') || die();

function booktool_wordimport_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $booknode) {
    global $CFG, $PAGE, $USER;

    if ($PAGE->cm->modname !== 'book') {
        return;
    }

    $params = $PAGE->url->params();
    if (empty($params['id']) and empty($params['cmid'])) {
        return;
    }

    if (empty($PAGE->cm->context)) {
        $PAGE->cm->context = get_context_module::instance($PAGE->cm->instance);
    }

    if (!(has_capability('booktool/wordimport:import', $PAGE->cm->context) and
          has_capability('mod/book:edit', $PAGE->cm->context) and
          property_exists($USER, 'editing') and $USER->editing)) {
        return;
    }

    $url = new moodle_url('/mod/book/tool/wordimport/index.php',
                          array('id' => $PAGE->cm->id));
    $booknode->add(get_string('importchapters', 'booktool_wordimport'),
                   $url, navigation_node::TYPE_SETTING, null, null, null);
}
