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

/*
 * @package    report
 * @subpackage mergefiles
 * @author
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL
 * @copyright  (C) 1999 onwards Martin Dougiamas  http://dougiamas.com
 *
 * The user selects if he wants to publish the course on Moodle.org hub or
 * on a specific hub. The site must be registered on a hub to be able to
 * publish a course on it.
 */

require('../../config.php');
require_once 'performmerge_form.php';
if(empty($id)){
	$id = required_param('courseid', PARAM_INT);
}
$course = $DB->get_record('course', array('id'=>$id), '*', MUST_EXIST);

require_login($course);
$context = context_course::instance($course->id);
$PAGE->set_pagelayout('incourse');

$strlastmodified = get_string('lastmodified');
$strlocation     = get_string('location');
$strintro        = get_string('moduleintro');
$strname         = get_string('name');
$strresources    = get_string('resources');
$strsectionname  = get_string('sectionname', 'format_'.$course->format);
$strsize		 = get_string('size');
$strsizeb		 = get_string('sizeb');

$heading = get_string('heading', 'report_mergefiles');
$note = get_string('note', 'report_mergefiles');
$pluginname		 = get_string('pluginname', 'report_mergefiles');

$PAGE->set_url('/report/mergefiles/index.php', array('id' => $course->id));
$PAGE->set_title($course->shortname.' | '.$pluginname);
$PAGE->set_heading($course->fullname.' | '.$pluginname);
$PAGE->navbar->add($pluginname);

//require_capability('report/mergefiles:view', $context);

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
echo $note;
echo "<br><br>";

// Source code from course/resources.php....used for getting all the pdf files in the course in order to merge them

// Get list of all resource-like modules
$allmodules = $DB->get_records('modules', array('visible'=>1));
$availableresources = array();
foreach ($allmodules as $key=>$module) {
	$modname = $module->name;
	$libfile = "$CFG->dirroot/mod/$modname/lib.php";
	if (!file_exists($libfile)) {
		continue;
	}
	$archetype = plugin_supports('mod', $modname, FEATURE_MOD_ARCHETYPE, MOD_ARCHETYPE_OTHER);
	if ($archetype != MOD_ARCHETYPE_RESOURCE) {
		continue;
	}

	$availableresources[] = $modname;	// List of all available resource types
}

$modinfo = get_fast_modinfo($course);	// Fetching all course data
$usesections = course_format_uses_sections($course->format);

$cms = array();
$resources = array();

foreach ($modinfo->cms as $cm) {	// Fetching all modules in the course, like forum, quiz, resource etc.
	if (!in_array($cm->modname, $availableresources)) {
		continue;
	}
	if (!$cm->uservisible) {
		continue;
	}
	if (!$cm->has_view()) {
		// Exclude label and similar
		continue;
	}
	$cms[$cm->id] = $cm;
	$resources[$cm->modname][] = $cm->instance;		// Fetch only modules having modname -'resource'..
													//..pdf files have modname 'resource'
}

// Preload instances
foreach ($resources as $modname=>$instances) {		// Getting data from mdl_resource table..id, name of the pdf file..
	$resources[$modname] = $DB->get_records_list($modname, 'id', $instances, 'id', 'id,name');
}

if (!$cms) {
	notice(get_string('thereareno', 'moodle', $strresources), "$CFG->wwwroot/course/view.php?id=$course->id");
	exit;
}

$table = new html_table();
$table->attributes['class'] = 'generaltable mod_index';

if ($usesections) {
	$strsectionname = get_string('sectionname', 'format_'.$course->format);
	$table->head  = array ($strsectionname, $strname, $strintro, $strsize.' ('.$strsizeb.')', "Location (moodledata/filedir/..)");
	$table->align = array ('center', 'left', 'left', 'left');
} else {
	$table->head  = array ($strlastmodified, $strname, $strintro, $strsize.' ('.$strsizeb.')', $strlocation);
	$table->align = array ('left', 'left', 'left', 'left');
}

$fs = get_file_storage();
$currentsection = '';
foreach ($cms as $cm) {
	if (!isset($resources[$cm->modname][$cm->instance])) {
		continue;
	}
	$resource = $resources[$cm->modname][$cm->instance];

	$printsection = '';
	if ($usesections) {
		if ($cm->sectionnum !== $currentsection) {
			if ($cm->sectionnum) {
				$printsection = get_section_name($course, $cm->sectionnum);
			}
			if ($currentsection !== '') {
				//$table->data[] = 'hr';
			}
			$currentsection = $cm->sectionnum;
		}
	}

	$extra = empty($cm->extra) ? '' : $cm->extra;
	$icon = '<img src="'.$cm->get_icon_url().'" class="activityicon" alt="'.$cm->get_module_type_name().'" /> ';
	$class = $cm->visible ? '' : 'class="dimmed"'; // hidden modules are dimmed

	//----------------------------------------------------------------------------
	// Source from mod/resource/view.php....used for getting contenthash of the file

	$context = context_module::instance($cm->id);
	$files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false); // TODO: this is not very efficient!!
	if (count($files) < 1) {
		//resource_print_filenotfound($resource, $cm, $course);
		continue;
	} else {
		$file = reset($files);
		unset($files);
	}

	// end of source from mod/resource/view.php
	//---------------------------------------------------------------------------

	$url = moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid(), $file->get_filepath(), $file->get_filename());
	$contenthash = $file->get_contenthash();

	static $p = 0;
	$loc[$p] = substr($contenthash,0,2).'/'.substr($contenthash,2,2).'/'.$contenthash;
	$arr[$p] = $CFG->dataroot."/filedir/".$loc[$p];

	$table->data[] = array (
			$printsection,
			"<a $class $extra href=\"".$url."\">".$icon.$cm->get_formatted_name()."</a>",
			$file->get_filename(),
			$file->get_filesize(),
			$loc[$p]);
	$p++;
}

echo html_writer::table($table);

$backuppath = $CFG->dataroot."/temp/filestorage/filebackup";	// create temporary storage location for merged pdf file
if (!file_exists($backuppath)) {
	$mkdir = mkdir($backuppath, 0777, true);
}
$backuppath .= "/";

$mform = new performmerge_form(null);
$formdata = array('courseid' => $id);
$mform->set_data($formdata);
$mform->display();

if ($data = $mform->get_data()) {
	if (!empty($data->save)) {

		// Code for merging all the course pdfs --------------------------------------------------------------------
		// merge all course pdf files and store the merged document at a temporary location

		$path = $CFG->dataroot."/temp/filestorage";	// create temporary storage location for merged pdf file
		if (!file_exists($path)) {
			$mkdir = mkdir($path, 0777, true);
		}
		$datadir = $path."/";

		$mergedpdf = $datadir.uniqid('mergedfile_').".pdf";	// path to the merged pdf document with unique filename

		// merge all the pdf files in the course using pdftk
		$cmd = "pdftk ";
		// add each pdf file to the command
		foreach($arr as $file) {
			$cmd .= $file." ";
		}
		$cmd .= " output $mergedpdf";
		$result = shell_exec($cmd);

		// copy the merged pdf document from temp loc to moodledata/filedir/..
		$mergedfileinfo = array(
				'contextid' => $context->id, 		// ID of context
				'component' => 'mod_resource',    	// usually = table name
				'filearea' 	=> 'content',     		// usually = table name
				'itemid' 	=> 0,               	// usually = ID of row in table
				'filepath' 	=> '/',           		// any path beginning and ending in /
				'filename' 	=> uniqid('mergedfile_').'.pdf'); 	// any filename

		$fs->create_file_from_pathname($mergedfileinfo, $mergedpdf);

		$mergedfile = $fs->get_file(
				$mergedfileinfo['contextid'],
				$mergedfileinfo['component'],
				$mergedfileinfo['filearea'],
				$mergedfileinfo['itemid'],
				$mergedfileinfo['filepath'],
				$mergedfileinfo['filename']);

		$mergedfileurl = moodle_url::make_pluginfile_url(
				$mergedfile -> get_contextid(),
				$mergedfile -> get_component(),
				$mergedfile -> get_filearea(),
				$mergedfile -> get_itemid(),
				$mergedfile -> get_filepath(),
				$mergedfile -> get_filename());

		// create a blank numbered pdf document --------------------------------------------------------------------

		// find no. of pages in the merged pdf document
		$noofpages = shell_exec("pdftk $mergedpdf dump_data | grep NumberOfPages | awk '{print $2}'");

		// latex script for creating blank numbered pdf document
		$startpage = 1;
		$texscript = '
	 		\documentclass[12pt,a4paper]{article}
	 		\usepackage{helvet}
	 		\usepackage{times}
	 		\usepackage{multido}
		 	\usepackage{fancyhdr}
			\usepackage[hmargin=.8cm,vmargin=1.5cm,nohead,nofoot]{geometry}
	 		\renewcommand{\familydefault}{\sfdefault}
	 		\begin{document}
	 		\fancyhf{} % clear all header and footer fields
	 		\renewcommand{\headrulewidth}{0pt}
	 		\pagestyle{fancy}
	 		%\rhead{{\large\bfseries\thepage}}
	 		\rhead{{\fbox{\large\bfseries\thepage}}}
	 		\setcounter{page}{'.$startpage.'}
	 		\multido{}{'.$noofpages.'}{\vphantom{x}\newpage}
	 		\end{document}
			';

		$tempfilename = uniqid('latexfile_');
		$latexfilename = $datadir.$tempfilename;
		$latexfile = $latexfilename.'.tex';

		$latexfileinfo = array(
				'contextid' => $context->id,
				'component' => 'mod_resource',
				'filearea' 	=> 'content',
				'itemid' 	=> 0,
				'filepath' 	=> '/',
				'filename' 	=> $tempfilename.'.tex');

		$fs->create_file_from_string($latexfileinfo, $texscript);

		$latexfile1 = $fs->get_file(
				$latexfileinfo['contextid'],
				$latexfileinfo['component'],
				$latexfileinfo['filearea'],
				$latexfileinfo['itemid'],
				$latexfileinfo['filepath'],
				$latexfileinfo['filename']);

		$latexfile1->copy_content_to($latexfile);

		// execute pdflatex with parameter
		// store the output blank numbered pdf document and all the intermediate files at the temp loc
		$result1 = shell_exec('pdflatex -aux-directory='.$datadir.' -output-directory='.$datadir.' '.$latexfile.' ');

		// var_dump( $pdflatex );
		// test for success
		if (!file_exists($latexfile)){
			print_r( file_get_contents($latexfilename.".log") );
		} else {
			//echo "\nPDF created!\n";
		}

		// merge the blank numbered pdf document with the merged pdf document (containing all course pdfs)

		$stampedpdf = $datadir.uniqid('stampedfile_').".pdf";	// unique filename (with entire path to the file) for the merged and stamped pdf document
		$result2 = shell_exec("pdftk $mergedpdf multistamp ".$latexfilename.".pdf output $stampedpdf");
		$stampedfilename = uniqid('stampedfile_').'.pdf';

		$stampedfileinfo = array(
				'contextid' => $context->id,
				'component' => 'mod_resource',
				'filearea' 	=> 'content',
				'itemid' 	=> 0,
				'filepath' 	=> '/',
				'filename' 	=> $stampedfilename);

		$fs->create_file_from_pathname($stampedfileinfo, $stampedpdf);

		$stampedfile = $fs->get_file(
				$stampedfileinfo['contextid'],
				$stampedfileinfo['component'],
				$stampedfileinfo['filearea'],
				$stampedfileinfo['itemid'],
				$stampedfileinfo['filepath'],
				$stampedfileinfo['filename']);

		$stampedfileurl = moodle_url::make_pluginfile_url(
				$stampedfile -> get_contextid(),
				$stampedfile -> get_component(),
				$stampedfile -> get_filearea(),
				$stampedfile -> get_itemid(),
				$stampedfile -> get_filepath(),
				$stampedfile -> get_filename());

		echo "<br> Merged PDF Document | "."<a $class $extra href=\"".$stampedfileurl."\">".$icon."Available here!</a>";

		$mergedpdf_bkup = $backuppath.$stampedfilename;
		$stampedfile->copy_content_to($mergedpdf_bkup);

	}
}
//----------------------------------------------------------------------------
// Source from mod/resource/view.php....used for getting contenthash of the file

//$context = context_module::instance($cm->id);
$files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false); // TODO: this is not very efficient!!
if (count($files) < 1) {
	//resource_print_filenotfound($resource, $cm, $course);
	continue;
} else {
	$file = reset($files);
	unset($files);
}
print_object($files);

// end of source from mod/resource/view.php
//---------------------------------------------------------------------------

$files1 = scandir($backuppath);
//print_r($files1);

$table1 = new html_table();
$table1->attributes['class'] = 'generaltable mod_index';

$table1->head  = array ("Previously merged files");
$table1->align = array ('center');

echo $OUTPUT->footer();