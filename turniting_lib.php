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
