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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External course participation api.
 *
 * This api is mostly read only, the actual enrol 
 * support is in each enrol plugin.
 *
 * @package    enrol_manual
 * @category   external
 * @copyright  2011 Jerome Mouneyrac
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.2
 */
class local_user_access_external extends external_api
{

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     * @since Moodle 2.2
     */
    public static function manage_enrolment_parameters()
    {
        return new external_function_parameters([
            'enrolments' => new external_multiple_structure(
                new external_single_structure([
                    'globalid' => new external_value(PARAM_RAW, 'The user globalId'),
                    'rolename' => new external_value(PARAM_RAW, 'Role'),
                    'coursecode' => new external_value(PARAM_RAW, 'Course code'),
                    'coursename' => new external_value(PARAM_RAW, 'Course name'),
                    'categorycode' => new external_value(PARAM_RAW, 'Category code'),
                    'creditpoints' => new external_value(PARAM_RAW, 'Credit points', VALUE_OPTIONAL),
                    'learninghours' => new external_value(PARAM_RAW, 'Learning hours', VALUE_OPTIONAL),
                    'practicalhours' => new external_value(PARAM_RAW, 'Practical hours', VALUE_OPTIONAL),
                    'startdate' => new external_value(PARAM_RAW, 'Start date', VALUE_OPTIONAL),
                    'enddate' => new external_value(PARAM_RAW, 'End date', VALUE_OPTIONAL),
                    'groupidnumber' => new external_value(PARAM_RAW, 'Group ID', VALUE_OPTIONAL),
                    'groupname' => new external_value(PARAM_RAW, 'Group name', VALUE_OPTIONAL),
                    'interfaceid' => new external_value(PARAM_RAW, 'Interface ID', VALUE_OPTIONAL),
                    'isactive' => new external_value(PARAM_INT, 'Status'),
                    'semester' => new external_value(PARAM_RAW, 'Semester', VALUE_OPTIONAL),
                ])
            )
        ]);
    }

    /**
     * Enrolment of users.
     *
     * Function throw an exception at the first error encountered.
     * @param array $enrolments  An array of user enrolment
     * @since Moodle 2.2
     */
    /**
     * Creates the required course and group (if they do not already exist),
     * enrols the user into the course, and adds the user to the specified group.
     *
     * @param array $enrolments Enrolment details received from the web service.
     * @return array Status of the enrolment operation.
     */
    public static function manage_enrolment($enrolments)
    {
        global $DB, $CFG;

        require_once($CFG->libdir . '/enrollib.php');
        require_once($CFG->dirroot . '/group/lib.php');
        require_once($CFG->dirroot . '/local/user_access/lib.php');

        $params = self::validate_parameters(
            self::manage_enrolment_parameters(),
            ['enrolments' => $enrolments]
        );

        $return = [];

        foreach ($params['enrolments'] as $enrolparams) {

            $logid = $DB->insert_record('local_api_logs', (object)[
                'external_function' => 'enrol_users_custom',
                'payload' => json_encode($enrolparams),
                'status' => 0,
                'timecreated' => time()
            ]);

            try {

                // Check whether the user exists.
                $user = $DB->get_record('user', ['idnumber' => $enrolparams['globalid']]);

                if (empty($user)) {
                    throw new moodle_exception('Invalid user.');
                }

                $userid = $user->id;

                // Check whether the course exists. Create it if it does not.
                $course = $DB->get_record('course', ['idnumber' => $enrolparams['coursecode']]);

                if (empty($course)) {
                    $startdate = user_access_get_timestamp(
                        $enrolparams['startdate'] ?? '',
                        'Start date'
                    );

                    $enddate = user_access_get_timestamp(
                        $enrolparams['enddate'] ?? '',
                        'End date'
                    );

                    $course = user_access_create_course(
                        $enrolparams['coursename'],
                        $enrolparams['coursecode'],
                        $enrolparams['categorycode'],
                        $startdate,
                        $enddate,
                        $enrolparams['semester'],
                        $enrolparams['creditpoints'],
                        $enrolparams['learninghours'],
                        $enrolparams['practicalhours']
                    );
                }

                $courseid = $course->id;

                // Check whether the group exists. Create it if it does not.
                $group = groups_get_group_by_idnumber($courseid, $enrolparams['groupidnumber']);
                if (empty($group)) {
                    $group = user_access_create_group(
                        $courseid,
                        $enrolparams['groupname'],
                        $enrolparams['groupidnumber']
                    );
                }

                $groupid = $group->id;

                // Enrol user.
                $enrolmentstatus = user_access_enrol_user(
                    $groupid,
                    $enrolparams['rolename'],
                    $courseid,
                    $userid,
                    $enrolparams['isactive'],
                    $logid
                );

                user_access_error_log(
                    $logid,
                    $enrolmentstatus['message'],
                    $enrolmentstatus['status']
                );
                if($enrolmentstatus['status'] == 1){
                    $msg = '';
                } else {
                    $msg = $enrolmentstatus['message'];
                }
                $return[] = [
                    'id' => $enrolparams['globalid'],
                    'issync' => $enrolmentstatus['status'],
                    'error' => $msg,
                    'interfaceid' => $enrolparams['interfaceid']
                ];
            } catch (\Throwable $e) {

                // Update log.
                user_access_error_log($logid, $e->getMessage(), 0);

                $return[] = [
                    'id' => $enrolparams['globalid'],
                    'issync' => 0,
                    'error' => $e->getMessage(),
                    'interfaceid' => $enrolparams['interfaceid']
                ];

                // Continue with next enrolment.
                continue;
            }
        }

        return $return;
    }


    /**
     * Returns description of method result value.
     *
     * @return null
     * @since Moodle 2.2
     */

    public static function manage_enrolment_returns()
    {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id' => new external_value(PARAM_INT, 'user id'),
                    'interfaceid' => new external_value(PARAM_RAW, 'interface id'),
                    'issync' => new external_value(PARAM_RAW, 'sync status'),
                    'error' => new external_value(PARAM_RAW, 'api error message')
                )
            )
        );
    }
}
