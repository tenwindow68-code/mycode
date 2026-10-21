<?php

/**
 * Enrols a user into a course using the manual enrolment method
 * and adds the user to the specified group.
 *
 * @param int $groupid Group ID.
 * @param string $rolename Role shortname.
 * @param int $courseid Course ID.
 * @param int $userid User ID.
 * @param int $isactive Active Status.
 * @param int $logid Log ID.
 * @return array Status and message.
 */
function user_access_enrol_user($groupid, $rolename, $courseid, $userid, $isactive, $logid)
{
    global $DB;

    // Find the manual enrolment instance for the course.
    $instance = null;
    foreach (enrol_get_instances($courseid, true) as $enrolinstance) {
        if ($enrolinstance->enrol === 'manual') {
            $instance = $enrolinstance;
            break;
        }
    }

    if (!empty($instance)) {

        // Get the manual enrolment plugin.
        $manual = enrol_get_plugin('manual');

        if (!empty($manual)) {
            if ($isactive == 0) {
                if (!groups_is_member($groupid, $userid)) {
                    user_access_error_log($logid, 'User is not mapped to this cohort.', 0);
                    return [
                        'status' => 0,
                        'message' => 'User was not mapped to this cohort.'
                    ];
                } else {
                    groups_remove_member($groupid, $userid);
                    if (empty(groups_get_all_groups($courseid, $userid))) {
                        $manual->unenrol_user($instance, $userid);
                    }
                    user_access_error_log($logid, 'User unmapped successfully.', 1);
                    return [
                        'status' => 1,
                        'message' => 'User unmapped successfully.'
                    ];
                }
            } else {
                // Get the role ID from the role shortname.
                $roleid = $DB->get_field('role', 'id', ['shortname' => $rolename]);
                if (!empty($roleid)) {
                    // Enrol the user if not already enrolled.
                    if (!is_enrolled(context_course::instance($courseid), $userid)) {
                        $manual->enrol_user($instance, $userid, $roleid);
                    }

                    // Add the user to the group if not already a member.
                    if (!groups_is_member($groupid, $userid)) {
                        groups_add_member($groupid, $userid);
                    }
                    user_access_error_log($logid, 'User mapped successfully.', 1);
                    return [
                        'status' => 1,
                        'message' => 'User mapped successfully.'
                    ];
                } else {
                    user_access_error_log($logid, 'Invalid role.', 0);
                    return [
                        'status' => 0,
                        'message' => 'Invalid role.'
                    ];
                }
            }
        } else {
            user_access_error_log($logid, 'Manual enrolment plugin is disabled.', 0);
            return [
                'status' => 0,
                'message' => 'Manual enrolment plugin is disabled.'
            ];
        }
    } else {
        user_access_error_log($logid, 'Manual enrolment instance not found.', 0);
        return [
            'status' => 0,
            'message' => 'Manual enrolment instance not found.'
        ];
    }
}


/**
 * Creates a new course if it does not already exist.
 *
 * @param string $fullname Course full name.
 * @param string $shortname Course short name.
 * @param string $categorycode Course category ID number.
 * @param int $startdate Course start timestamp.
 * @param int $enddate Course end timestamp.
 * @param int $creditpoints Course creditpoints.
 * @param int $learninghours Course learninghours.
 * @param int $practicalhours Course practicalhours.
 * @param array $semester Course semester.
 * @return stdClass Created course object.
 */
function user_access_create_course($fullname, $shortname, $categorycode, $startdate, $enddate, 
$semester, $creditpoints, $learninghours, $practicalhours)
{
    global $DB, $CFG;
    require_once($CFG->dirroot . '/course/lib.php');

    // Get the category ID using its ID number.
    $categoryid = $DB->get_field('course_categories', 'id', [
        'idnumber' => $categorycode
    ]);
    if (empty($categoryid)) {
        throw new moodle_exception('Invalid category.');
    }

    // Prepare course data.
    $coursedata = new stdClass();
    $coursedata->fullname = $fullname;
    $coursedata->shortname = $shortname;
    $coursedata->idnumber = $shortname;
    $coursedata->category = $categoryid;
    $coursedata->startdate = $startdate;
    $coursedata->enddate = $enddate;
    $coursedata->creditpoints = $creditpoints;
    $coursedata->learninghours = $learninghours;
    $coursedata->practicalhours = $practicalhours;
    $coursedata->visible = 1;
    $coursedata->customfield_semester = $semester;
    // Create the course.
    return create_course($coursedata);
}

/**
 * Creates a group within a course.
 *
 * @param int $courseid Course ID.
 * @param string $groupname Group name.
 * @param string $groupidnumber Group ID number.
 * @return stdClass Group data.
 */
function user_access_create_group($courseid, $groupname, $groupidnumber)
{

    // Prepare group data.
    $groupdata = new stdClass();
    $groupdata->courseid = $courseid;
    $groupdata->name = $groupname;
    $groupdata->idnumber = $groupidnumber;

    // Create the group.
    groups_create_group($groupdata);

    return $groupdata;
}

/**
 * Creates a log
 *
 * @param int $logid Log ID.
 * @param string $response response.
 * @param int $status Status.
 * @return stdClass log data.
 */
function user_access_error_log($logid, $response, $status)
{
    global $DB;
    $DB->update_record('local_api_logs', (object)[
        'id' => $logid,
        'status' => $status,
        'response' => $response,
        'timemodified' => time()
    ]);
}
/**
 * Validates and converts a date to a Unix timestamp.
 *
 * @param string|int $date
 * @param string $fieldname
 * @return int
 * @throws moodle_exception
 */
function user_access_get_timestamp($date, $fieldname = 'Date') {

    // Empty value is allowed.
    if (empty($date)) {
        return 0;
    }

    // Already a Unix timestamp.
    if (is_numeric($date) && ctype_digit((string)$date)) {
        return (int)$date;
    }

    // Try converting a date string.
    $timestamp = strtotime($date);

    if ($timestamp === false) {
        throw new moodle_exception("$fieldname is not a valid date.");
    }

    return $timestamp;
}