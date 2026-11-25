
<?php
defined('MOODLE_INTERNAL') || die();

class assign_submission_externalstorage extends assign_submission_plugin {

    const UPLOAD_ELEMENT = 'externalstorage_file';

    /**
     * Plugin name.
     */
    public function get_name() {
        return get_string('pluginname', 'assignsubmission_externalstorage');
    }

    /**
     * Get external file metadata.
     */
    private function get_file_submission(int $submissionid) {
        global $DB;

        return $DB->get_record(
            'assignsubmission_externalstorage',
            ['submission' => $submissionid],
            '*',
            IGNORE_MISSING
        );
    }

    /**
     * Add custom upload input to Moodle's assignment form.
     */
    
public function get_form_elements($submission, MoodleQuickForm $mform, stdClass $data) {
    global $CFG;

    $mform->updateAttributes([
        'enctype' => 'multipart/form-data'
    ]);

    $record = false;

    if ($submission && !empty($submission->id)) {
        $record = $this->get_file_submission((int)$submission->id);
    }

    // Read plugin upload limit.
    $pluginmaxbytes = (int)get_config(
        'assignsubmission_externalstorage',
        'maxbytes'
    );

    // Calculate Moodle's effective site/course upload limit.
    $course = $this->assignment->get_course();

    $moodlemaxbytes = get_max_upload_file_size(
        $CFG->maxbytes,
        $course->maxbytes
    );

    // Apply the smaller positive limit.
    if ($pluginmaxbytes > 0 && $moodlemaxbytes > 0) {
        $maxbytes = min($pluginmaxbytes, $moodlemaxbytes);
    } else if ($pluginmaxbytes > 0) {
        $maxbytes = $pluginmaxbytes;
    } else {
        $maxbytes = $moodlemaxbytes;
    }

    // Add direct upload field.
//    $mform->addElement(
//        'file',
//        self::UPLOAD_ELEMENT,
//        get_string('uploadfile', 'assignsubmission_externalstorage')
//    );
    $mform->addElement(
    'filemanager',
    self::UPLOAD_ELEMENT,
    get_string('uploadfile', 'assignsubmission_externalstorage'),
    null,
    [
        'subdirs' => 0,
        'maxbytes' => $maxbytes,
        'maxfiles' => 1,
        'accepted_types' => '*'
    ]
);
    $mform->addRule(
    self::UPLOAD_ELEMENT,
    null,
    'required',
    null,
    'client'
);

    // IMPORTANT: Set the actual hidden MAX_FILE_SIZE value.
//    $mform->setMaxFileSize($maxbytes);

    // Existing submission information.
    $mform->addElement(
        'hidden',
        'externalstorage_existing',
        $record ? 1 : 0
    );

    $mform->setType('externalstorage_existing', PARAM_INT);

    if ($record) {
        $currentfile = s($record->filename) . ' (' .
            display_size($record->filesize) . ')';

        $mform->addElement(
            'static',
            'externalstorage_current',
            get_string('currentfile', 'assignsubmission_externalstorage'),
            $currentfile
        );
    }

    return true;
}


    /**
     * Check whether the request contains an upload attempt.
     */
    private function has_upload_attempt(): bool {

        if (!isset($_FILES[self::UPLOAD_ELEMENT])) {//vraj
            return false;
        }

        return ($_FILES[self::UPLOAD_ELEMENT]['error'] ?? UPLOAD_ERR_NO_FILE)
            !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Check whether the uploaded file is valid.
     */
    private function has_valid_upload(): bool {
//        echo "Error line no:103";die;
        if (!$this->has_upload_attempt()) {
            return false;
        }

        $upload = $_FILES[self::UPLOAD_ELEMENT];

        return ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && !empty($upload['tmp_name'])
            && is_uploaded_file($upload['tmp_name']);
    }

    /**
     * Moodle checks whether incoming submission data is empty.
     */
//public function submission_is_empty(stdClass $data) {
//
//    if ($this->has_upload_attempt()) {
//        return false;
//    }
////echo "debug here";die;
//    return empty($data->externalstorage_existing);
//}
    
    public function submission_is_empty(stdClass $data) {

    if (!empty($data->{self::UPLOAD_ELEMENT})) {
        return false;
    }

    if (!empty($data->externalstorage_existing)) {
        return false;
    }

    return true;
}

    /**
     * Check whether a saved submission contains an external file.
     */
    public function is_empty(stdClass $submissionorgrade) {

        if (empty($submissionorgrade->id)) {
            return true;
        }

        return !$this->get_file_submission((int)$submissionorgrade->id);
    }

    /**
     * Validate the submission before saving.
     */
    public function precheck_submission($submission) {

        // FIRST: accept a valid new upload.
        // Do not require a database record at this stage.
        if ($this->has_valid_upload()) {
            return true;
        }

        // An upload was attempted but PHP reported an error.
        if ($this->has_upload_attempt()) {
            $upload = $_FILES[self::UPLOAD_ELEMENT];

            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return get_string(
                    'uploadfailed',
                    'assignsubmission_externalstorage'
                );
            }
        }

        // No new upload: allow an existing saved file.
        if ($submission && !empty($submission->id)) {

            if ($this->get_file_submission((int)$submission->id)) {
                return true;
            }
        }

        return get_string(
            'nofileuploaded',
            'assignsubmission_externalstorage'
        );
    }

    /**
     * Get configured external storage root.
     */
    private function get_storage_root() {

        $configured = get_config(
            'assignsubmission_externalstorage',
            'storagepath'
        );

        if (empty($configured) ||
                !is_dir($configured) ||
                !is_writable($configured)) {
            return false;
        }

        return realpath($configured);
    }

    /**
     * Resolve a plugin-generated storage key safely.
     */
    private function get_absolute_path(string $storagekey) {

        $root = $this->get_storage_root();

        if (!$root) {
            return false;
        }

        $pattern = '#^course_[0-9]+/assign_[0-9]+/submission_[0-9]+/[a-f0-9]{32}\.blob$#';

        if (!preg_match($pattern, $storagekey)) {
            return false;
        }

        $path = $root . DIRECTORY_SEPARATOR .
            str_replace('/', DIRECTORY_SEPARATOR, $storagekey);

        if (!is_file($path)) {
            return false;
        }

        $realpath = realpath($path);

        if (!$realpath ||
                strpos($realpath, $root . DIRECTORY_SEPARATOR) !== 0) {
            return false;
        }

        return $realpath;
    }

    /**
     * Save uploaded file directly to external storage.
     */
    public function save(stdClass $submission, stdClass $data) {
        global $DB;

        if (empty($submission->id)) {
            $this->set_error(
                get_string('uploadfailed', 'assignsubmission_externalstorage')
            );
            return false;
        }

        $submissionid = (int)$submission->id;
        $upload = $_FILES[self::UPLOAD_ELEMENT] ?? null;

        // No replacement upload: retain existing file.
        if (!$upload ||
                ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {

            if ($this->get_file_submission($submissionid)) {
                return true;
            }

            $this->set_error(
                get_string('nofileuploaded', 'assignsubmission_externalstorage')
            );

            return false;
        }

        // Validate upload error.
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->set_error(
                get_string('uploadfailed', 'assignsubmission_externalstorage')
            );
            return false;
        }

        if (empty($upload['tmp_name']) ||
                !is_uploaded_file($upload['tmp_name'])) {
            $this->set_error(
                get_string('uploadfailed', 'assignsubmission_externalstorage')
            );
            return false;
        }

        // Sanitize original filename.
        $originalname = str_replace('\\', '/', $upload['name'] ?? '');
        $filename = clean_param(basename($originalname), PARAM_FILE);

        if ($filename === '') {
            $this->set_error(
                get_string('invalidfilename', 'assignsubmission_externalstorage')
            );
            return false;
        }

        $filesize = (int)$upload['size'];

        // Check configured maximum file size.
        $maxbytes = (int)get_config(
            'assignsubmission_externalstorage',
            'maxbytes'
        );

        if ($maxbytes > 0 && $filesize > $maxbytes) {
            $this->set_error(
                get_string('filetoolarge', 'assignsubmission_externalstorage')
            );
            return false;
        }

        // Check external storage root.
        $root = $this->get_storage_root();

        if (!$root) {
            $this->set_error(
                get_string('storageunavailable', 'assignsubmission_externalstorage')
            );
            return false;
        }

        // Get assignment and course identifiers.
        $cm = $this->assignment->get_course_module();
        $courseid = (int)$this->assignment->get_course()->id;
        $assignmentid = (int)$cm->instance;

        // Generate a random server-controlled physical filename.
        try {
            $randomname = bin2hex(random_bytes(16)) . '.blob';
        } catch (\Throwable $e) {
            $this->set_error(
                get_string('uploadfailed', 'assignsubmission_externalstorage')
            );
            return false;
        }

        $relativepath = "course_{$courseid}/assign_{$assignmentid}/submission_{$submissionid}";

        $directory = $root . DIRECTORY_SEPARATOR .
            str_replace('/', DIRECTORY_SEPARATOR, $relativepath);

        if (!is_dir($directory) &&
                !mkdir($directory, 0770, true) &&
                !is_dir($directory)) {
            $this->set_error(
                get_string('storageunavailable', 'assignsubmission_externalstorage')
            );
            return false;
        }

        $realroot = realpath($root);
        $realdirectory = realpath($directory);

        if (!$realdirectory ||
                strpos($realdirectory, $realroot . DIRECTORY_SEPARATOR) !== 0 ||
                !is_writable($realdirectory)) {
            $this->set_error(
                get_string('storageunavailable', 'assignsubmission_externalstorage')
            );
            return false;
        }

        $storagekey = $relativepath . '/' . $randomname;
        $destination = $realdirectory . DIRECTORY_SEPARATOR . $randomname;

        // Keep old metadata until the replacement is safely saved.
        $oldrecord = $this->get_file_submission($submissionid);

        // Detect MIME type from file content.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimetype = $finfo->file($upload['tmp_name']);

        if (!$mimetype) {
            $mimetype = 'application/octet-stream';
        }

        // Move PHP temporary upload directly to external storage.
        if (!move_uploaded_file($upload['tmp_name'], $destination)) {
            $this->set_error(
                get_string('uploadfailed', 'assignsubmission_externalstorage')
            );
            return false;
        }

        $now = time();

        $record = new stdClass();
        $record->assignment = $assignmentid;
        $record->submission = $submissionid;
        $record->userid = !empty($submission->userid)
            ? (int)$submission->userid
            : 0;
        $record->filename = $filename;
        $record->storagekey = $storagekey;
        $record->filesize = $filesize;
        $record->mimetype = $mimetype;
        $record->timemodified = $now;

        try {
            if ($oldrecord) {
                $record->id = $oldrecord->id;
                $record->timecreated = $oldrecord->timecreated;

                $DB->update_record(
                    'assignsubmission_externalstorage',
                    $record
                );
            } else {
                $record->timecreated = $now;

                $DB->insert_record(
                    'assignsubmission_externalstorage',
                    $record
                );
            }
        } catch (\Throwable $e) {
            @unlink($destination);

//            debugging(
//                'External storage metadata error: ' . $e->getMessage(),
//                DEBUG_DEVELOPER
//            );

            $this->set_error(
                get_string('uploadfailed', 'assignsubmission_externalstorage')
            );

            return false;
        }

        // Delete old file only after successful database update.
        if ($oldrecord && $oldrecord->storagekey !== $storagekey) {
            $oldpath = $this->get_absolute_path($oldrecord->storagekey);

            if ($oldpath) {
                @unlink($oldpath);
            }
        }

        return true;
    }

    /**
     * Display file link in assignment summary.
     */
    
public function view_summary(stdClass $submission, &$showviewlink) {

    $showviewlink = false;

    $record = $this->get_file_submission((int)$submission->id);

    if (!$record) {
        return '';
    }

    $url = new moodle_url(
        '/mod/assign/submission/externalstorage/download.php',
        ['submissionid' => $submission->id]
    );

    // File types that should open in a new browser tab.
    $extension = strtolower(pathinfo($record->filename, PATHINFO_EXTENSION));

    $inlineextensions = [
    'pdf',
    'jpg',
    'jpeg',
    'png',
    'gif',
    'webp',
    'txt',
    'mp3',
    'wav',
    'ogg',
    'mp4',
    'webm',
];

    $attributes = [];

    if (in_array($extension, $inlineextensions, true)) {
        $attributes = [
            'target' => '_blank',
            'rel' => 'noopener noreferrer',
        ];
    }

    return html_writer::link(
        $url,
        s($record->filename),
        $attributes
    );
}


    /**
     * Display full submission.
     */
    public function view(stdClass $submissionorgrade) {

        if (empty($submissionorgrade->id)) {
            return '';
        }

        $showviewlink = false;

        return $this->view_summary($submissionorgrade, $showviewlink);
    }

    /**
     * Remove external file and metadata.
     */
    public function remove(stdClass $submission) {
    global $DB;

    $record = $this->get_file_submission((int)$submission->id);

    if (!$record) {
        return true;
    }

    $path = $this->get_absolute_path($record->storagekey);

    // Delete the physical file first.
    if ($path && file_exists($path)) {
        if (!unlink($path)) {
            return false;
        }
    }

    // Delete metadata only after successful file deletion.
    $DB->delete_records(
        'assignsubmission_externalstorage',
        ['id' => $record->id]
    );

    return true;
}

    /**
     * Delete all external files belonging to this assignment.
     */
    public function delete_instance() {
        global $DB;

        $assignmentid = (int)$this->assignment->get_course_module()->instance;

        $records = $DB->get_records(
            'assignsubmission_externalstorage',
            ['assignment' => $assignmentid]
        );

        foreach ($records as $record) {
            $path = $this->get_absolute_path($record->storagekey);

            if ($path) {
                @unlink($path);
            }
        }

        $DB->delete_records(
            'assignsubmission_externalstorage',
            ['assignment' => $assignmentid]
        );

        return true;
    }
}
