<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.


/**
 * Progress Bar block definition
 *
 * @package    contrib
 * @subpackage block_custom_progress
 * @copyright  2010 Michael de Raadt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot.'/blocks/custom_progress/lib.php');

/**
 * Progress Bar block class
 *
 * @copyright 2010 Michael de Raadt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_custom_progress extends block_base {

    /**
     * Sets the block title
     *
     * @return void
     */
    public function init() {
        $this->title = get_string('config_default_title', 'block_custom_progress');
    }

    /**
     *  we have global config/settings data
     *
     * @return bool
     */
    public function has_config() {
        return true;
    }

    /**
     * Controls the block title based on instance configuration
     *
     * @return bool
     */
    public function specialization() {
        if (isset($this->config->custom_progressTitle) && trim($this->config->custom_progressTitle) != '') {
            $this->title = format_string($this->config->custom_progressTitle);
        }
    }

    /**
     * Controls whether multiple instances of the block are allowed on a page
     *
     * @return bool
     */
    public function instance_allow_multiple() {
        return !block_custom_progress_on_site_page();
    }

    /**
     * Controls whether the block is configurable
     *
     * @return bool
     */
    public function instance_allow_config() {
        return !block_custom_progress_on_site_page();
    }

    /**
     * Defines where the block can be added
     *
     * @return array
     */
    public function applicable_formats() {
        return array(
            'course-view'    => true,
            'site'           => true,
            'mod'            => false,
            'my'             => true
        );
    }

    /**
     * Creates the blocks main content
     *
     * @return string
     */
    public function get_content() {
        global $USER, $COURSE, $CFG, $OUTPUT, $DB;

        // If content has already been generated, don't waste time generating it again.
        if ($this->content !== null) {
            return $this->content;
        }
        $this->content = new stdClass;
        $this->content->text = '';
        $this->content->footer = '';
        $blockinstancesonpage = array();

        // Guests do not have any custom_progress. Don't show them the block.
        if (!isloggedin() or isguestuser()) {
            return $this->content;
        }

        // Draw the multi-bar content for the Dashboard and Front page.
        if (block_custom_progress_on_site_page()) {
            $courses = enrol_get_my_courses();
            $coursenametoshow = get_config('block_custom_progress', 'coursenametoshow') ?: DEFAULT_COURSENAMETOSHOW;
            $sql = "SELECT bi.id,
                           bp.id AS blockpositionid,
                           COALESCE(bp.region, bi.defaultregion) AS region,
                           COALESCE(bp.weight, bi.defaultweight) AS weight,
                           COALESCE(bp.visible, 1) AS visible,
                           bi.configdata
                      FROM {block_instances} bi
                 LEFT JOIN {block_positions} bp ON bp.blockinstanceid = bi.id
                                               AND ".$DB->sql_like('bp.pagetype', ':pagetype', false)."
                     WHERE bi.blockname = 'custom_progress'
                       AND bi.parentcontextid = :contextid
                  ORDER BY region, weight, bi.id";

            // Show a message when the user is not enrolled in any courses.
            if (($this->page->user_is_editing() || is_siteadmin()) && empty($courses)) {
                $this->content->text = get_string('no_courses', 'block_custom_progress');
                return $this->content;
            }

            foreach ($courses as $courseid => $course) {

                // Get specific block config and context.
                $modules = block_custom_progress_modules_in_use($course->id);
                if ($course->visible && !empty($modules)) {
                    $context = block_custom_progress_get_course_context($course->id);
                    $params = array('contextid' => $context->id, 'pagetype' => 'course-view-%');
                    $blockinstances = $DB->get_records_sql($sql, $params);
                    foreach ($blockinstances as $blockid => $blockinstance) {
                        $blockinstance->config = unserialize(base64_decode($blockinstance->configdata));
                        if (!empty($blockinstance->config)) {
                            $blockinstance->events = block_custom_progress_event_information(
                                                         $blockinstance->config,
                                                         $modules,
                                                         $course->id);
                            $blockinstance->events = block_custom_progress_filter_visibility($blockinstance->events,
                                                         $USER->id, $context, $course);
                        }
                        $blockcontext = block_custom_progress_get_block_context($blockid);
                        if (
                            !has_capability('block/custom_progress:showbar', $blockcontext) ||
                            $blockinstance->visible == 0 ||
                            empty($blockinstance->config) ||
                            $blockinstance->events == 0 ||
                            (
                                !empty($blockinstance->config->group) &&
                                !has_capability('moodle/site:accessallgroups', $context) &&
                                !groups_is_member($blockinstance->config->group, $USER->id)
                            )
                        ) {
                            unset($blockinstances[$blockid]);
                        }
                    }
                    $blockinstancesonpage = array_merge($blockinstancesonpage, array_keys($blockinstances));

                    // Output the Progress Bar.
                    if (!empty($blockinstances)) {
                        $courselink = new moodle_url('/course/view.php', array('id' => $course->id));
                        $linktext = HTML_WRITER::tag('h3', s($course->$coursenametoshow));
                        $this->content->text .= HTML_WRITER::link($courselink, $linktext);
                    }
                    foreach ($blockinstances as $blockid => $blockinstance) {
                        if ($blockinstance->config->custom_progressTitle != '') {
                            $this->content->text .= HTML_WRITER::tag('p', s(format_string($blockinstance->config->custom_progressTitle)));
                        }
                        $attempts = block_custom_progress_attempts($modules,
                                                            $blockinstance->config,
                                                            $blockinstance->events,
                                                            $USER->id,
                                                            $course->id);
                        $percent = block_custom_progress_percentage( $attempts,
                                                                   $blockinstance->events,
                                                                   $blockinstance->config);
                        $this->content->text .= block_custom_progress_badge($percent, $blockinstance->id,
                                                                   $blockinstance->config);
                        $this->content->text .= block_custom_progress_bar($percent);
                    }
                }
            }

            // Show a message explaining lack of bars, but only while editing is on.
            if ($this->page->user_is_editing() && $this->content->text == '') {
                $this->content->text = get_string('no_blocks', 'block_custom_progress');
            }
        }

        // Gather content for block on regular course.
        else {

            // Check if user is in group for block.
            if (
                !empty($this->config->group) &&
                !has_capability('moodle/site:accessallgroups', $this->context) &&
                !groups_is_member($this->config->group, $USER->id)
            ) {
                return $this->content;
            }

            // Check if any activities/resources have been created.
            $modules = block_custom_progress_modules_in_use($COURSE->id);
            if (empty($modules)) {
                if (has_capability('moodle/block:edit', $this->context)) {
                    $this->content->text .= get_string('no_events_config_message', 'block_custom_progress');
                }
                return $this->content;
            }

            // Check if activities/resources have been selected in config.
            $events = block_custom_progress_event_information($this->config, $modules, $COURSE->id);
            $context = block_custom_progress_get_course_context($COURSE->id);
            $events = block_custom_progress_filter_visibility($events, $USER->id, $context);
            if ($events === null || $events === 0) {
                if (has_capability('moodle/block:edit', $this->context)) {
                    $this->content->text .= get_string('no_events_message', 'block_custom_progress');
                    if ($USER->editing) {
                        $parameters = array('id' => $COURSE->id, 'sesskey' => sesskey(),
                                            'bui_editid' => $this->instance->id);
                        $url = new moodle_url('/course/view.php', $parameters);
                        $label = get_string('selectitemstobeadded', 'block_custom_progress');
                        $this->content->text .= $OUTPUT->single_button($url, $label);
                        if ($events === 0) {
                            $url->param('turnallon', '1');
                            $label = get_string('addallcurrentitems', 'block_custom_progress');
                            $this->content->text .= $OUTPUT->single_button($url, $label);
                        }
                    }
                }
                return $this->content;
            } else if (empty($events)) {
                if (has_capability('moodle/block:edit', $this->context)) {
                    $this->content->text .= get_string('no_visible_events_message', 'block_custom_progress');
                }
                return $this->content;
            }

            // Display custom_progress bar.
            if (has_capability('block/custom_progress:showbar', $this->context)) {
                $attempts = block_custom_progress_attempts($modules, $this->config, $events, $USER->id, $COURSE->id);
                $percent = block_custom_progress_percentage( $attempts,
                                                           $events,
                                                           $this->config);
                $this->content->text .= block_custom_progress_badge($percent,$this->instance->id,
                                                           $this->config);
                $this->content->text .= block_custom_progress_bar($percent);
            }
            $blockinstancesonpage = array($this->instance->id);

            // Allow teachers to access the overview page.
            if (has_capability('block/custom_progress:overview', $this->context)) {
                $parameters = array('custom_progressbarid' => $this->instance->id, 'courseid' => $COURSE->id);
                $url = new moodle_url('/blocks/custom_progress/overview.php', $parameters);
                $label = get_string('overview', 'block_custom_progress');
                $options = array('class' => 'overviewButton');
                $this->content->text .= $OUTPUT->single_button($url, $label, 'post', $options);
            }
        }


        return $this->content;
    }

    /**
     * Serialize and store config data
     */
    function instance_config_save($data, $nolongerused = false) {
        global $DB;

        $config = clone($data);
        // Move embedded files into a proper filearea and adjust HTML links to match
        if (isset($config->levels_pix)){
            $config->levels_pix = file_save_draft_area_files($data->levels_pix, $this->context->id, 'block_custom_progress', 'badges', 0, array('subdirs'=>true));
        }
        parent::instance_config_save($config, $nolongerused);
    }

    function instance_delete() {
        global $DB;
        $fs = get_file_storage();
        $fs->delete_area_files($this->context->id, 'block_custom_progress');
        return true;
    }

    /**
     * Copy any block-specific data when copying to a new block instance.
     * @param int $fromid the id number of the block instance to copy from
     * @return boolean
     */
    public function instance_copy($fromid) {
        $fromcontext = context_block::instance($fromid);
        $fs = get_file_storage();
        // This extra check if file area is empty adds one query if it is not empty but saves several if it is.
        if (!$fs->is_area_empty($fromcontext->id, 'block_custom_progress', 'badges', 0, false)) {
            $draftitemid = 0;
            file_prepare_draft_area($draftitemid, $fromcontext->id, 'block_custom_progress', 'badges', 0, array('subdirs' => true));
            file_save_draft_area_files($draftitemid, $this->context->id, 'block_custom_progress', 'badges', 0, array('subdirs' => true));
        }
        return true;
    }
}
