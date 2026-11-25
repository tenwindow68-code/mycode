public function queue_submission_to_turnitin($cm, $author, $submitter, $identifier, $submissiontype, $itemid = 0,
        $eventtype = null) {
        global $CFG, $DB, $turnitinacceptedfiles;
        $errorcode = 0;
        $attempt = 0;
        $tiisubmissionid = null;

        // If the EULA hasn't been accepted, don't save submission and don't submit to Tii.
        $coursedata = $this->get_course_data($cm->id, $cm->course);
        $user = new turnitin_user($author, "Learner");
        $user->join_user_to_class($coursedata->turnitin_cid);
        $eulaaccepted = ($user->useragreementaccepted == 0) ? $user->get_accepted_user_agreement() : $user->useragreementaccepted;
        if ($eulaaccepted != 1) {
            return true;
        }

        // If the submission is already in the queue in an error state, remove it
        $DB->delete_records('plagiarism_turnitin_files', [
                'cm' => $cm->id,
                'userid' => $author,
                'itemid' => $itemid,
                'statuscode' => 'error',
            ]
        );

        // Check if file has been submitted before.
        $plagiarismfiles = plagiarism_turnitin_retrieve_successful_submissions($author, $cm->id, $identifier);
        if (count($plagiarismfiles) > 0) {
            return true;
        }

        $settings = $this->get_settings($cm->id);

        if ((!isset($settings["plagiarism_compare_student_papers"]) || !$settings["plagiarism_compare_student_papers"]) &&
            (!isset($settings["plagiarism_compare_internet"]) || !$settings["plagiarism_compare_internet"]) &&
            (!isset($settings["plagiarism_compare_journals"]) || !$settings["plagiarism_compare_journals"]) &&
            (!isset($settings["plagiarism_compare_institution"]) || !$settings["plagiarism_compare_institution"])) {
            // If all comparison options are disabled then don't submit to Turnitin.
            plagiarism_turnitin_activitylog('No comparison options selected for assignment with cmid: '.$cm->id.' not sending to Turnitin', 'NO_COMPARISON_OPTIONS_SELECTED');
            return true;
        }
        // Get module data.
        $moduledata = $DB->get_record($cm->modname, ['id' => $cm->instance]);
        $moduledata->resubmission_allowed = false;

        if ($cm->modname == 'assign') {
            // Group submissions require userid = 0 when checking assign_submission.
            $userid = ($moduledata->teamsubmission) ? 0 : $author;

            if (!isset($_SESSION["moodlesubmissionstatus"])) {
                $_SESSION["moodlesubmissionstatus"] = null;
            }

            if ($eventtype == "content_uploaded" || $eventtype == "file_uploaded") {
                $moodlesubmission = $DB->get_record('assign_submission',
                    ['assignment' => $cm->instance,
                        'userid' => $userid,
                        'id' => $itemid, ], 'status');

                $_SESSION["moodlesubmissionstatus"] = $moodlesubmission->status;
            }

            $turnitinassign = new turnitin_assign();
            $moduledata->resubmission_allowed = $turnitinassign->is_resubmission_allowed(
                $cm->instance,
                $settings["plagiarism_report_gen"],
                $submissiontype,
                $moduledata->maxattempts,
                $_SESSION["moodlesubmissionstatus"]
            );

            if ($eventtype != "content_uploaded" && $eventtype != "file_uploaded") {
                unset($_SESSION["moodlesubmissionstatus"]);
            }
        } else {
            $userid = $author;
        }

        // Work out submission method.
        // If this file has successfully submitted in the past then break, text content is to be submitted.
        switch ($submissiontype) {
            case 'file':
            case 'text_content':

                // Get file data or prepare text submission.
                if ($submissiontype == 'file') {
                    $fs = get_file_storage();
                    $file = $fs->get_file_by_hash($identifier);

                    $timemodified = $file->get_timemodified();
                    $filename = $file->get_filename();
                } else {
                    // Check when text submission was last modified.
                    switch ($cm->modname) {
                        case 'assign':
                            $moodlesubmission = $DB->get_record('assign_submission',
                                                    ['assignment' => $cm->instance,
                                                                'userid' => $userid,
                                                                'id' => $itemid, ], 'timemodified');
                            break;
                        case 'workshop':
                            $moodlesubmission = $DB->get_record('workshop_submissions',
                                                    ['workshopid' => $cm->instance,
                                                            'authorid' => $userid, ], 'timemodified');
                            break;
                    }

                    $timemodified = $moodlesubmission->timemodified;
                }

                // Get submission method depending on whether there has been a previous submission.
                $submissionfields = 'id, cm, externalid, identifier, statuscode, lastmodified, attempt';
                $typefield = ($CFG->dbtype == "oci") ? " to_char(submissiontype) " : " submissiontype ";

                // Check if this content/file has been submitted previously.
                $previoussubmissions = $DB->get_records_select('plagiarism_turnitin_files',
                                                    " cm = ? AND userid = ? AND ".$typefield." = ? AND identifier = ?",
                                                [$cm->id, $author, $submissiontype, $identifier],
                                                    'id', $submissionfields);
                $previoussubmission = end($previoussubmissions);

                if ($previoussubmission) {
                    // Don't submit if submission hasn't changed.
                    if (in_array($previoussubmission->statuscode, ["success", "error"])
                            && $timemodified <= $previoussubmission->lastmodified) {
                        return true;
                    } else if ($moduledata->resubmission_allowed) {
                        // Replace submission in the specific circumstance where Turnitin can accommodate resubmissions.
                        $submissionid = $previoussubmission->id;
                        $this->reset_tii_submission($cm, $author, $identifier, $previoussubmission, $submissiontype);
                        $tiisubmissionid = $previoussubmission->externalid;
                    } else {
                        if ($previoussubmission->statuscode != "success") {
                            $submissionid = $previoussubmission->id;
                            $this->reset_tii_submission($cm, $author, $identifier, $previoussubmission, $submissiontype);
                        } else {
                            $submissionid = $this->create_new_tii_submission($cm, $author, $identifier, $submissiontype);
                            $tiisubmissionid = $previoussubmission->externalid;
                        }
                    }
                    $attempt = $previoussubmission->attempt;
                } else {
                    // Check if there is previous submission of different content which we may be able to replace.
                    $typefield = ($CFG->dbtype == "oci") ? " to_char(submissiontype) " : " submissiontype ";
                    if ($previoussubmission = $DB->get_record_select('plagiarism_turnitin_files',
                                                    " cm = ? AND userid = ? AND ".$typefield." = ?",
                                                [$cm->id, $author, $submissiontype],
                                                    'id, cm, externalid, identifier, statuscode, lastmodified, attempt')) {

                        $submissionid = $previoussubmission->id;
                        $attempt = $previoussubmission->attempt;
                        // Delete old text content submissions from Turnitin if resubmissions aren't allowed.
                        if ($submissiontype == 'text_content' && $settings["plagiarism_report_gen"] == 0 &&
                            !is_null($previoussubmission->externalid)) {
                            $this->delete_tii_submission($cm, $previoussubmission->externalid, $author);
                        }

                        // Replace submission in the specific circumstance where Turnitin can accomodate resubmissions.
                        if ($moduledata->resubmission_allowed || $submissiontype == 'text_content') {
                            $this->reset_tii_submission($cm, $author, $identifier, $previoussubmission, $submissiontype);
                            $tiisubmissionid = $previoussubmission->externalid;
                        } else {
                            $submissionid = $this->create_new_tii_submission($cm, $author, $identifier, $submissiontype);
                        }

                    } else {
                        $submissionid = $this->create_new_tii_submission($cm, $author, $identifier, $submissiontype);
                    }
                }

                break;

            case 'forum_post':
            case 'quiz_answer':
                if ($previoussubmissions = $DB->get_records_select('plagiarism_turnitin_files',
                                                    " cm = ? AND userid = ? AND identifier = ? ",
                                                    [$cm->id, $author, $identifier],
                                                    'id DESC', 'id, cm, externalid, identifier, statuscode, attempt', 0, 1)) {

                    $previoussubmission = current($previoussubmissions);
                    if ($previoussubmission->statuscode == "success") {
                        return true;
                    } else {
                        $submissionid = $previoussubmission->id;
                        $attempt = $previoussubmission->attempt;
                        $tiisubmissionid = $previoussubmission->externalid;
                        $this->reset_tii_submission($cm, $author, $identifier, $previoussubmission, $submissiontype);
                    }
                } else {
                    $submissionid = $this->create_new_tii_submission($cm, $author, $identifier, $submissiontype);
                }
                break;
        }

        // Check file is less than maximum allowed size.
        if ($submissiontype == 'file') {
            if ($file->get_filesize() > PLAGIARISM_TURNITIN_MAX_FILE_UPLOAD_SIZE) {
                $errorcode = 2;
            }
        }

        // If applicable, check whether file type is accepted.
        $acceptanyfiletype = (!empty($settings["plagiarism_allow_non_or_submissions"])) ? 1 : 0;
        if (!$acceptanyfiletype && $submissiontype == 'file') {
            $filenameparts = explode('.', $filename);
            $fileext = strtolower(end($filenameparts));
            if (!in_array(".".$fileext, $turnitinacceptedfiles)) {
                $errorcode = 4;
            }
        }

        // Save submission as queued or errored if we have an errorcode.
        $statuscode = ($errorcode != 0) ? 'error' : 'queued';
        return $this->save_submission($cm, $author, $submissionid, $identifier, $statuscode, $tiisubmissionid, $submitter, $itemid,
                        $submissiontype, $attempt, $errorcode);
    }

public function event_handler($eventdata) {
        global $DB;

        $result = true;

        // Get the coursemodule, use a different method if in a quiz as we have the quiz id.
        if ($eventdata['other']['modulename'] == 'quiz') {
            $cm = get_coursemodule_from_instance($eventdata['other']['modulename'], $eventdata['other']['quizid']);
        } else {
            $cm = get_coursemodule_from_id($eventdata['other']['modulename'], $eventdata['contextinstanceid']);
        }

        // Remove the event if the course module no longer exists.
        if (!$cm) {
            return true;
        }
        $context = context_module::instance($cm->id);

        // Initialise module settings.
        $plagiarismsettings = $this->get_settings($cm->id);
        $moduletiienabled = $this->get_config_settings('mod_'.$cm->modname);
        if ($cm->modname == 'assign') {
            $plagiarismsettings["plagiarism_draft_submit"] = (isset($plagiarismsettings["plagiarism_draft_submit"])) ?
                $plagiarismsettings["plagiarism_draft_submit"] : 0;
        }

        // Either module not using Turnitin or Turnitin not being used at all so return true to remove event from queue.
        if (empty($plagiarismsettings['use_turnitin']) || empty($moduletiienabled)) {
            return true;
        }

        // Get module data.
        $moduledata = $DB->get_record($cm->modname, ['id' => $cm->instance]);
        if ($cm->modname != 'assign') {
            $moduledata->submissiondrafts = 0;
        }

        // If draft submissions are turned on then only send to Turnitin if the draft submit setting is set.
        if ($moduledata->submissiondrafts && $plagiarismsettings["plagiarism_draft_submit"] == 1 &&
            ($eventdata['eventtype'] == 'file_uploaded' || $eventdata['eventtype'] == 'content_uploaded')) {
            return true;
        }

        // Set the author and submitter.
        $submitter = $eventdata['userid'];
        $author = (!empty($eventdata['relateduserid'])) ? $eventdata['relateduserid'] : $eventdata['userid'];

        /*
           Related user ID will be NULL if an instructor submits on behalf of a student who is in a group.
           To get around this, we get the group ID, get the group members and set the author as the first student in the group.
        */
        if ((empty($eventdata['relateduserid'])) && ($cm->modname == 'assign')
                && has_capability('mod/assign:editothersubmission', $context, $submitter)) {
            $moodlesubmission = $DB->get_record('assign_submission', ['id' => $eventdata['objectid']], 'id, groupid');
            if (!empty($moodlesubmission->groupid)) {
                $author = $this->get_first_group_author($cm->course, $moodlesubmission->groupid);
            }
        }

        // Get actual text content and files to be submitted for draft submissions.
        // As this won't be present in eventdata for certain event types.
        if ($eventdata['other']['modulename'] == 'assign' && $eventdata['eventtype'] == "assessable_submitted") {
            // Get content.
            $moodlesubmission = $DB->get_record('assign_submission', ['id' => $eventdata['objectid']], 'id');
            if ($moodletextsubmission = $DB->get_record('assignsubmission_onlinetext',
                                        ['submission' => $moodlesubmission->id], 'onlinetext')) {
                $eventdata['other']['content'] = $moodletextsubmission->onlinetext;
            }

            // Get Files.
            $eventdata['other']['pathnamehashes'] = [];
            $filesconditions = ['component' => 'assignsubmission_file',
                                    'itemid' => $moodlesubmission->id, 'userid' => $author, ];
            if ($moodlefiles = $DB->get_records('files', $filesconditions)) {
                foreach ($moodlefiles as $moodlefile) {
                    $eventdata['other']['pathnamehashes'][] = $moodlefile->pathnamehash;
                }
            }
        }

        // Remove submission from Turnitin queue if it is removed from Moodle.
        if ($eventdata['other']['modulename'] == 'assign' && $eventdata['eventtype'] == "submission_removed") {
            $params = [
                'cm' => $eventdata['contextinstanceid'],
                'userid' => $eventdata['relateduserid'],
                'itemid' => $eventdata['objectid'],
                'statuscode' => 'queued',
            ];
            $DB->delete_records('plagiarism_turnitin_files', $params);
        }

        // Queue every question submitted in a quiz attempt.
        if ($eventdata['eventtype'] == 'quiz_submitted') {

            if (class_exists('\mod_quiz\quiz_attempt')) {
                $quizattemptclass = '\mod_quiz\quiz_attempt';
            } else {
                $quizattemptclass = 'quiz_attempt';
            }
            $attempt = $quizattemptclass::create($eventdata['objectid']);

            foreach ($attempt->get_slots() as $slot) {
                $qa = $attempt->get_question_attempt($slot);
                if ($qa->get_question()->get_type_name() != 'essay') {
                    continue;
                }
                $eventdata['other']['content'] = $qa->get_response_summary();

                // Queue text content.
                // We don't have access to the text content in the event handler, so use userid, cmid, slot, and attempt number to create a unique hash
                $identifier = sha1('quiz_attempt user'.$attempt->get_userid().' cm'.$cm->id.' slot'.$slot.' attempt'.$attempt->get_attempt_number());
                $result = $this->queue_submission_to_turnitin(
                        $cm, $author, $submitter, $identifier, 'quiz_answer',
                        $eventdata['objectid'], $eventdata['eventtype']);

                $files = $qa->get_last_qt_files('attachments', $context->id);
                foreach ($files as $file) {
                    // Queue file for sending to Turnitin.
                    $identifier = $file->get_pathnamehash();
                    $result = $this->queue_submission_to_turnitin(
                            $cm, $author, $submitter, $identifier, 'file',
                            $eventdata['objectid'], $eventdata['eventtype']);
                }
            }
        }

        // Queue text content and forum posts to send to Turnitin.
        if (in_array($eventdata['eventtype'], ["content_uploaded", "assessable_submitted"])
                && !empty($eventdata['other']['content'])) {

            $submissiontype = ($cm->modname == 'forum') ? 'forum_post' : 'text_content';

            // The content inside the event data will not always correspond to the content we will look up later, e.g.
            // because URLs have been converted to use @@PLUGINFILE@@ etc. Therefore to calculate the same hash, we need to
            // do a lookup to get the file content
            if ($cm->modname == 'workshop') {
                $moodlesubmission = $DB->get_record('workshop_submissions', ['id' => $eventdata['objectid']]);
                $eventdata['other']['content'] = $moodlesubmission->content;
            } else if ($cm->modname == 'forum') {
                $moodlesubmission = $DB->get_record('forum_posts', ['id' => $eventdata['objectid']]);
                $eventdata['other']['content'] = $moodlesubmission->message;
            }

            $identifier = sha1($eventdata['other']['content']);

            // Check if content has been submitted before and return if so.
            $result = $this->queue_submission_to_turnitin(
                            $cm, $author, $submitter, $identifier, $submissiontype,
                            $eventdata['objectid'], $eventdata['eventtype']);
        }

        // Queue files to submit to Turnitin.
        $result = $result && true;
        if (!empty($eventdata['other']['pathnamehashes'])) {
            foreach ($eventdata['other']['pathnamehashes'] as $pathnamehash) {
                $fs = get_file_storage();
                $file = $fs->get_file_by_hash($pathnamehash);

                if (!$file) {
                    plagiarism_turnitin_activitylog('File not found: '.$pathnamehash, 'PP_NO_FILE');
                    $result = true;
                    continue;
                } else if ($file->get_filename() === '.') {
                    continue;
                } else {
                    try {
                        $fh = $file->get_content_file_handle();
                        fclose($fh);
                    } catch (Exception $e) {
                        plagiarism_turnitin_activitylog('File content not found: '.$pathnamehash, 'PP_NO_FILE');
                        mtrace($e);
                        mtrace('File content not found. pathnamehash: '.$pathnamehash);
                        $result = true;
                        continue;
                    }
                }

                $result = $result && $this->queue_submission_to_turnitin(
                            $cm, $author, $submitter, $pathnamehash, 'file', $eventdata['objectid'], $eventdata['eventtype']);
            }
        }

        return $result;
    }
