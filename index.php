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
 * A report to display an approval indicator summary
 *
 * @package    report_approvalindicator
 * @copyright 2017 David Herney Bernal - cirano
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once('locallib.php');
require_once('filters/lib.php');

$sort           = optional_param('sort', 'fullname', PARAM_ALPHANUM);
$dir            = optional_param('dir', 'ASC', PARAM_ALPHA);
$page           = optional_param('page', 0, PARAM_INT);
$perpage        = optional_param('perpage', 30, PARAM_INT);
$format         = optional_param('format', '', PARAM_ALPHA);
$who            = optional_param('who', 'summary', PARAM_ALPHA);


admin_externalpage_setup('reportapprovalindicator', '', null, '', array('pagelayout' => 'report'));

$baseurl = new moodle_url('/report/approvalindicator/index.php', array('sort' => $sort, 'dir' => $dir, 'perpage' => $perpage, 'page'=>$page));

// Create the filter form.
$filtering = new approvalindicator_filtering();

list($extrasql, $params) = $filtering->get_sql_filter('enablecompletion = 1');

if ($format) {
    $perpage = 0;
}

$courses = $DB->get_records_select('course', $extrasql, $params, $sort . ' ' . $dir, '*', $page * $perpage, $perpage);
$coursesearchcount = $DB->count_records_select('course', $extrasql, $params);
$coursecount = $DB->count_records('course', array('enablecompletion' => 1));

if ($courses) {

    $categories = $DB->get_records('course_categories');

    $stringcolumns = array(
        'id' => 'id',
        'fullname' => get_string('course'),
        'startdate' => get_string('startdate', 'report_approvalindicator'),
        'enddate' => get_string('enddate', 'report_approvalindicator'),
        'timecompleted' => get_string('timecompleted', 'report_approvalindicator'),
        'category' => get_string('category'),
        'student' => get_string('student', 'report_approvalindicator'),
        'username' => get_string('username'),
        'notcompleted' => get_string('notcompletedlabel', 'report_approvalindicator'),
        'enrolledusers' => get_string('enrolledusers', 'enrol'),
        'completedpercent' => get_string('completedpercent', 'report_approvalindicator')
    );

    $strcsystem = get_string('categorysystem', 'report_approvalindicator');
    $strftimedate = get_string('strftimedatetimeshort');
    $strfdate = get_string('strftimedatefullshort');
    $strnever = get_string('never');

    // Only download data.
    if ($format) {
        if ($who == 'summary') {
            $columns = array('id', 'fullname', 'category', 'enrolledusers', 'notcompleted', 'completedpercent');
        } else {
            $columns = array('id', 'fullname', 'category', 'username', 'student', 'timecompleted');
        }

        $fields = array();
        foreach ($columns as $column) {
            $fields[$column] = $stringcolumns[$column];
        }

        $data = array();
        $userscache = array();

        $courseid = optional_param('courseid', 0, PARAM_INT);

        if ($courseid) {
            $course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
            $courses = array($course);
        }

        foreach ($courses as $row) {

            $coursecontext = context_course::instance($row->id);

            $textcats = '';

            if (!$row->category) {
                $textcats = $strcsystem;
            } else {
                $cats = trim($categories[$row->category]->path, '/');
                $cats = explode('/', $cats);
                foreach ($cats as $key => $cat) {
                    if (!empty($cat)) {
                        $cats[$key] = $categories[$cat]->name;
                    }
                }

                $textcats = implode(' / ', $cats);
            }

            $sql = 'SELECT ra.id, ra.roleid, cc.timecompleted AS timecompleted, ra.userid
                        FROM {role_assignments} AS ra
                        LEFT JOIN {course_completions} AS cc ON cc.course = :courseid AND cc.userid = ra.userid
                        WHERE ra.contextid = :contextid AND ra.roleid IN (' . $CFG->gradebookroles . ')';
            $rolecounts = $DB->get_records_sql($sql, array('contextid' => $coursecontext->id, 'courseid' => $row->id));

            $userslist = array();
            if ($rolecounts & count($rolecounts) > 0) {
                $enrolledusers = count($rolecounts);
                $enrolleduserscompletion = 0;

                foreach($rolecounts as $oneassign) {

                    if ($who == 'summary') {
                        if ($oneassign->timecompleted) {
                            $enrolleduserscompletion++;
                        }
                    } else {

                        if ($who == 'completed' && !$oneassign->timecompleted) {
                            continue;
                        } else if ($who == 'notcompleted' && $oneassign->timecompleted) {
                            $enrolleduserscompletion++;
                            continue;
                        }


                        if (isset($userscache[$oneassign->userid])) {
                            $user = $userscache[$oneassign->userid];
                        } else {
                            $user = $DB->get_record('user', array('id' => $oneassign->userid));
                            $userscache[$oneassign->userid] = $user;
                        }

                        $userinfo = new stdClass();
                        $userinfo->id       = $user->id;
                        $userinfo->fullname = '';
                        $userinfo->category = '';
                        $userinfo->username = $user->username;
                        $userinfo->student  = fullname($user);

                        if ($oneassign->timecompleted) {
                            $userinfo->timecompleted = userdate($oneassign->timecompleted, $strftimedate);
                            $enrolleduserscompletion++;
                        } else {
                            $userinfo->timecompleted = $stringcolumns['notcompleted'];
                        }

                        $userslist[] = $userinfo;
                    }
                }

                $enrolleduserspercent = round($enrolleduserscompletion * 100 / $enrolledusers);

            } else {
                $enrolledusers = 0;
                $enrolleduserscompletion = 0;
                $enrolleduserspercent = 0;
            }

            $datarow = new stdClass();
            $datarow->id = $row->id;
            $datarow->fullname = $row->fullname;
            $datarow->category = $textcats;
            $data[] = $datarow;

            if ($who != 'summary') {
                $datarow->username = '';
                $datarow->student = $enrolledusers;
                $datarow->timecompleted = $enrolleduserscompletion;

                $data = array_merge($data, $userslist);
            } else {
                $datarow->notcompleted = $enrolledusers - $enrolleduserscompletion;
                $datarow->enrolledusers = $enrolledusers;
                $datarow->completedpercent = $enrolledusers === 0 ? '' : $enrolleduserspercent . '%';
            }

        }

        switch ($format) {
            case 'csv' : approvalindicator_download_csv($fields, $data);
            case 'ods' : approvalindicator_download_ods($fields, $data);
            case 'xls' : approvalindicator_download_xls($fields, $data);

        }
        die;
    }
    // End download data.
}

echo $OUTPUT->header();

flush();


$content = '';

if ($courses) {

    foreach ($courses as $row) {

        $coursecontext = context_course::instance($row->id);

        // Prepare a cell to display the status of the entry.
        $statusclass = '';
        if (!$row->visible) {
            $statusclass = 'dimmed_text';
        }

        if (!$row->category) {
            $textcats = $strcsystem;
        } else {
            $cats = trim($categories[$row->category]->path, '/');
            $cats = explode('/', $cats);
            foreach ($cats as $key => $cat) {
                if (!empty($cat)) {
                    $cats[$key] = html_writer::tag('a',
                                    html_writer::tag('span', $categories[$cat]->name, array('class' => 'singleline')),
                                    array('href' => new moodle_url('/course/index.php',
                                                        array('categoryid' => $categories[$cat]->id)))
                                );
                }
            }

            $textcats = implode(' / ', $cats);
        }

        $sql = 'SELECT ra.id, ra.roleid, cc.timecompleted AS timecompleted
                    FROM {role_assignments} AS ra
                    LEFT JOIN {course_completions} AS cc ON cc.course = :courseid AND cc.userid = ra.userid
                    WHERE ra.contextid = :contextid AND ra.roleid IN (' . $CFG->gradebookroles . ')';
        $rolecounts = $DB->get_records_sql($sql, array('contextid' => $coursecontext->id, 'courseid' => $row->id));

        if ($rolecounts & count($rolecounts) > 0) {
            $enrolledusers = count($rolecounts);
            $enrolleduserscompletion = 0;
            foreach($rolecounts as $oneassign) {
                if ($oneassign->timecompleted) {
                    $enrolleduserscompletion++;
                }
            }
            $enrolleduserspercent = round($enrolleduserscompletion * 100 / $enrolledusers);
        } else {
            $enrolledusers = 0;
            $enrolleduserscompletion = 0;
            $enrolleduserspercent = 0;
        }

        $coursename = html_writer::tag('a', $row->fullname,
                        array('href' => new moodle_url('/course/view.php', array('id' => $row->id))));

        $coursecontent = html_writer::tag('h3', $coursename);

        if ($enrolledusers == 0) {
            $coursecontent .= html_writer::tag('p', get_string('notenrolledusers', 'report_approvalindicator'));
        } else {

            $icondownload = new pix_icon('i/export', get_string('download'), 'moodle');

            $url = $baseurl . '&format=csv&who=all&courseid=' . $row->id;
            $userscontent = html_writer::tag('span', $enrolleduserscompletion,
                array('data-completed-action' => 'show', 'data-course-id' => $row->id));

            $enroledcontent = html_writer::tag('span', $enrolledusers,
                array('data-all-action' => 'show', 'data-course-id' => $row->id));

            $a = new stdClass();
            $a->users = $userscontent;
            $a->all = $enroledcontent;
            $coursecontent .= html_writer::tag('p', get_string('userscompletion', 'report_approvalindicator', $a) .
                $OUTPUT->action_icon($url, $icondownload));

            $coursecontent .= html_writer::start_tag('div', array('class' => 'indicatorbox'));
            $coursecontent .= html_writer::tag('div', $enrolleduserspercent . '%', array('class' => 'percentlabel'));
            $coursecontent .= html_writer::tag('div', '', array('class' => 'percentbar', 'style' => 'width: ' . $enrolleduserspercent . '%;'));
            $coursecontent .= html_writer::end_tag('div');
        }
        $coursecontent .= html_writer::tag('p', $textcats);


        $content .= $OUTPUT->box_start('approvalindicatorcourse ' . $statusclass) . $coursecontent . $OUTPUT->box_end();

    }

    $templatecontext = [
        'title' => '',
        'body' => '',
        'footer' => ''
    ];

    $content .= html_writer::start_tag('div', array('id' => 'approvalindicator_modal'));
    $content .= $OUTPUT->render_from_template('core/modal', $templatecontext);
    $content .= html_writer::end_tag('div');

    $PAGE->requires->js_call_amd('report_approvalindicator/approvalindicator_actions', 'init', array('root' => '#approvalindicator_modal > div'));
}

if ($extrasql !== '' && $coursesearchcount !== $coursecount) {
    echo $OUTPUT->heading("$coursesearchcount / $coursecount " . get_string('courses'));
    $coursecount = $coursesearchcount;
} else {
    echo $OUTPUT->heading($coursecount . ' ' . get_string('courses'));
}

echo $OUTPUT->paging_bar($coursecount, $page, $perpage, $baseurl);

// Add filters.
$filtering->display_add();
$filtering->display_active();

if (!empty($content)) {
    echo $OUTPUT->box_start();

    echo $content;

    echo $OUTPUT->box_end();

    echo $OUTPUT->paging_bar($coursecount, $page, $perpage, $baseurl);


    // Download form.
    echo $OUTPUT->heading(get_string('download', 'admin'));

    echo $OUTPUT->box_start();
    echo '<form action="' . $baseurl . '">';
    echo '  <select name="format">';
    echo '    <option value="csv">' . get_string('downloadtext') . '</option>';
    echo '    <option value="ods">' . get_string('downloadods') . '</option>';
    echo '    <option value="xls">' . get_string('downloadexcel') . '</option>';
    echo '  </select>';
    echo '  <select name="who">';
    echo '    <option value="summary">' . get_string('summary', 'report_approvalindicator') . '</option>';
    echo '    <option value="all">' . get_string('allusers', 'report_approvalindicator') . '</option>';
    echo '    <option value="completed">' . get_string('onlycompleted', 'report_approvalindicator') . '</option>';
    echo '    <option value="notcompleted">' . get_string('notcompleted', 'report_approvalindicator') . '</option>';
    echo '  </select>';
    echo '  <input type="submit" value="' . get_string('export', 'report_approvalindicator') . '" />';
    echo '</form>';
    echo $OUTPUT->box_end();

} else {
    echo $OUTPUT->heading(get_string('notcoursesfound', 'report_approvalindicator'), 3);
}

echo $OUTPUT->footer();
