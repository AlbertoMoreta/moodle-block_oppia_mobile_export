<?php 
/**
 * Oppia Mobile Export
 * Step 2: Configure password protection (for sections and feedback activities)
 */

require_once(dirname(__FILE__) . '/../../../config.php');
require_once(dirname(__FILE__) . '/../constants.php');

require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/lib/filestorage/file_storage.php');

require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/feedback/lib.php');
require_once($CFG->dirroot . '/question/format/gift/format.php');

$pluginroot = $CFG->dirroot . PLUGINPATH;

require_once($pluginroot . 'lib.php');
require_once($pluginroot . 'langfilter.php');
require_once($pluginroot . 'oppia_api_helper.php');
require_once($pluginroot . 'activity/activity.class.php');
require_once($pluginroot . 'activity/page.php');
require_once($pluginroot . 'activity/quiz.php');
require_once($pluginroot . 'activity/resource.php');
require_once($pluginroot . 'activity/feedback.php');
require_once($pluginroot . 'activity/url.php');

require_once($CFG->libdir.'/componentlib.class.php');

// We get all the params from the previous step form
$id = required_param('id', PARAM_INT);
$stylesheet = required_param('stylesheet', PARAM_TEXT);
$priority = required_param('coursepriority', PARAM_INT);
$sequencing = required_param('coursesequencing', PARAM_TEXT);
$DEFAULT_LANG = required_param('default_lang', PARAM_TEXT);
$keep_html = optional_param('keep_html', false, PARAM_BOOL);
$server = required_param('server', PARAM_TEXT);
$course_export_status = required_param('course_export_status', PARAM_TEXT);
$thumb_height = required_param('thumb_height', PARAM_INT);
$thumb_width = required_param('thumb_width', PARAM_INT);
$section_height = required_param('section_height', PARAM_INT);
$section_width = required_param('section_width', PARAM_INT);
$tags = required_param('coursetags', PARAM_TEXT);
$tags = cleanTagList($tags);

$course = $DB->get_record('course', array('id'=>$id));

$PAGE->set_url(PLUGINPATH.'export/step2.php', array('id' => $id));
context_helper::preload_course($id);
$context = context_course::instance($course->id);
if (!$context) {
	print_error('nocontext');
}

require_login($course);

$CFG->cachejs = false;

$PAGE->requires->jquery();
$PAGE->set_pagelayout('course');
$PAGE->set_pagetype('course-view-' . $course->format);
$PAGE->set_other_editing_capability('moodle/course:manageactivities');
$PAGE->set_title(get_string('course') . ': ' . $course->fullname);
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();

$PAGE->requires->js(PLUGINPATH.'publish/publish_media.js');

global $MOBILE_LANGS;
$MOBILE_LANGS = array();

global $MEDIA;
$MEDIA = array();

// Save new export configurations for this course and server
add_or_update_oppiaconfig($id, 'coursepriority', $priority, $server);
add_or_update_oppiaconfig($id, 'coursetags', $tags, $server);
add_or_update_oppiaconfig($id, 'coursesequencing', $sequencing, $server);
add_or_update_oppiaconfig($id, 'default_lang', $DEFAULT_LANG, $server);
add_or_update_oppiaconfig($id, 'keep_html', $keep_html, $server);
add_or_update_oppiaconfig($id, 'thumb_height', $thumb_height, $server);
add_or_update_oppiaconfig($id, 'thumb_width', $thumb_width, $server);
add_or_update_oppiaconfig($id, 'section_height', $section_height, $server);
add_or_update_oppiaconfig($id, 'section_width', $section_width, $server);

$PAGE->set_context($context);
context_helper::preload_course($id);
$modinfo = get_fast_modinfo($course);
$sections = $modinfo->get_section_info_all();
$mods = $modinfo->get_cms();

add_publishing_log($server, $USER->id, $id, "export_start", "Export process starting");

$a = new stdClass();
$a->stepno = 2;
$a->coursename = strip_tags($course->fullname);
echo "<h2>".get_string('export_title', PLUGINNAME, $a)."</h2>";
echo '<div class="oppia_export_section py-3">';

$config_sections = array();
$sect_orderno = 1;
foreach($sections as $sect) {
	flush_buffers();
	// We avoid the topic0 as is not a section as the rest
	if ($sect->section == 0) {
	    continue;
	}

	$sectionmods = explode(",", $sect->sequence);
	$defaultSectionTitle = false;
	$sectionTitle = format_string($sect->summary);
	// If the course has no summary, we try to use the section name
	if ($sectionTitle == "") {
		$sectionTitle = format_string($sect->name);
	}
	// If the course has neither summary nor name, use the default topic title
	if ($sectionTitle == "") {
		$sectionTitle = get_string('sectionname', 'format_topics') . ' ' . $sect->section;
		$defaultSectionTitle = true;
	}

	if(count($sectionmods)>0){
		$activity_count = 0;
		$activities = [];

		foreach ($sectionmods as $modnumber) {
			if ($modnumber == "" || $modnumber === false){
				continue;
			}
			$mod = $mods[$modnumber];
			
			if($mod->visible != 1){
				continue;
			}
			if ( ($mod->modname == 'page') ||
					($mod->modname == 'resource') || 
					($mod->modname == 'url')) {
				$activity_count++;
			}
			else if ($mod->modname == 'feedback'){
				$activity_count++;

				$password = get_oppiaconfig($mod->id, 'password', '', $server);

				array_push($activities, array(
					'modid' => $mod->id,
					'title' => format_string($mod->name),
					'password' => $password
				));
			} 
			else if($mod->modname == 'quiz'){
				$activity_count++;
				// For the quizzes, we save the configuration entered
				$random = optional_param('quiz_'.$mod->id.'_randomselect', 0, PARAM_INT);
				$showfeedback = optional_param('quiz_'.$mod->id.'_showfeedback', 1, PARAM_INT);
				$passthreshold = optional_param('quiz_'.$mod->id.'_passthreshold', 0, PARAM_INT);
				$maxattempts = optional_param('quiz_'.$mod->id.'_maxattempts', 'unlimited', PARAM_INT);
				
				if($maxattempts == 0){
				    $maxattempts = 'unlimited';
				}
				add_or_update_oppiaconfig($mod->id, 'randomselect', $random);
				add_or_update_oppiaconfig($mod->id, 'showfeedback', $showfeedback);
				add_or_update_oppiaconfig($mod->id, 'passthreshold', $passthreshold);
				add_or_update_oppiaconfig($mod->id, 'maxattempts', $maxattempts);
			}
		}

		if ($activity_count > 0){

			$password = get_oppiaconfig($sect->id, 'password', '', $server);

			array_push($config_sections, array(
				'sect_orderno' => $sect_orderno,
				'sect_id' => $sect->id,
				'password' => $password,
				'activity_count' => $activity_count,
				'title' => $sectionTitle,
				'activities' => $activities
			));
			$sect_orderno++;
		} 
		else{
			echo '<div class="step">'.get_string('section_password_invalid', PLUGINNAME, $sectionTitle).'</div>';
			
		}
		flush_buffers();
	}
}
echo '</div>';

if ($sect_orderno <= 1){
	echo '<h3>'.get_string('error_exporting', PLUGINNAME).'</h3>';
	echo '<p>'.get_string('error_exporting_no_sections', PLUGINNAME).'</p>';
	echo $OUTPUT->footer();
	die();
}

echo $OUTPUT->render_from_template(
	PLUGINNAME.'/export_step2_form', 
	array(
		'id' => $id,
		'server_id' => $server,
		'stylesheet' => $stylesheet,
		'course_export_status' => $course_export_status,
		'sections' => $config_sections,
		'wwwroot' => $CFG->wwwroot));

echo $OUTPUT->footer();

?>
