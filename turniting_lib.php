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
