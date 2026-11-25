
<?php
defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/classes/local/azure_blob_storage.php');

use assignsubmission_externalstorage\local\azure_blob_storage;

/**
 * Azure Blob Storage assignment submission plugin.
 */
class assign_submission_externalstorage extends assign_submission_plugin {

    const UPLOAD_ELEMENT = 'externalstorage_file';

    /**
     * Return plugin name.
     */
    public function get_name() {
        return get_string(
            'pluginname',
            'assignsubmission_externalstorage'
        );
    }

    /**
     * Get submission metadata.
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
     * Add upload element to assignment form.
     */
    
public function get_form_elements(
    $submission,
    MoodleQuickForm $mform,
    stdClass $data
) {
    global $CFG;

    $mform->updateAttributes([
        'enctype' => 'multipart/form-data'
    ]);

    $record = false;

    if ($submission && !empty($submission->id)) {
        $record = $this->get_file_submission(
            (int)$submission->id
        );
    }

    $pluginmaxbytes = (int)get_config(
        'assignsubmission_externalstorage',
        'maxbytes'
    );

    $course = $this->assignment->get_course();

    $moodlemaxbytes = get_max_upload_file_size(
        $CFG->maxbytes,
        $course->maxbytes
    );

    if ($pluginmaxbytes > 0 && $moodlemaxbytes > 0) {
        $maxbytes = min($pluginmaxbytes, $moodlemaxbytes);
    } else if ($pluginmaxbytes > 0) {
        $maxbytes = $pluginmaxbytes;
    } else {
        $maxbytes = $moodlemaxbytes;
    }
    // Moodle native file picker.
    $mform->addElement(
        'file',
        self::UPLOAD_ELEMENT,
        get_string(
            'uploadfile',
            'assignsubmission_externalstorage'
        ),
        ['class' => 'externalstorage-file-input']
    );

    $mform->setMaxFileSize($maxbytes);
    $mform->setType('MAX_FILE_SIZE', PARAM_INT);



    // Display existing submission.
    if ($record) {

        $filedetails = html_writer::div(
            html_writer::tag(
                'strong',
                s($record->filename)
            ) .
            html_writer::tag(
                'span',
                display_size($record->filesize),
                ['class' => 'externalstorage-file-size']
            ),
            'externalstorage-current-file-details'
        );

        $currentfile = html_writer::div(
            html_writer::div(
                'Existing submission',
                'externalstorage-current-file-heading'
            ) .
            $filedetails,
            'externalstorage-current-file'
        );

        $mform->addElement(
            'static',
            'externalstorage_current',
            '',
            $currentfile
        );
    }

    // Preserve existing submission state.
    $mform->addElement(
        'hidden',
        'externalstorage_existing',
        $record ? 1 : 0
    );

    $mform->setType(
        'externalstorage_existing',
        PARAM_INT
    );

    return true;
}


    /**
     * Check whether a file upload was attempted.
     */
    private function has_upload_attempt(): bool {
        if (!isset($_FILES[self::UPLOAD_ELEMENT])) {
            return false;
        }

        return (
            $_FILES[self::UPLOAD_ELEMENT]['error']
            ?? UPLOAD_ERR_NO_FILE
        ) !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * Check uploaded file validity.
     */
    private function has_valid_upload(): bool {
        if (!$this->has_upload_attempt()) {
            return false;
        }

        $upload = $_FILES[self::UPLOAD_ELEMENT];

        return ($upload['error'] ?? UPLOAD_ERR_NO_FILE)
                === UPLOAD_ERR_OK
            && !empty($upload['tmp_name'])
            && is_uploaded_file($upload['tmp_name']);
    }

    /**
     * Check whether the submission form is empty.
     */
    public function submission_is_empty(stdClass $data) {
        if ($this->has_upload_attempt()) {
            return false;
        }

        return empty($data->externalstorage_existing);
    }

    /**
     * Check whether a submission has a file.
     */
    public function is_empty(stdClass $submissionorgrade) {
        if (empty($submissionorgrade->id)) {
            return true;
        }

        return !$this->get_file_submission(
            (int)$submissionorgrade->id
        );
    }

    /**
     * Validate submission before saving.
     */
    public function precheck_submission($submission) {

        if ($this->has_valid_upload()) {
            return true;
        }

        if ($this->has_upload_attempt()) {
            return get_string(
                'uploadfailed',
                'assignsubmission_externalstorage'
            );
        }

        if ($submission && !empty($submission->id)) {
            if ($this->get_file_submission(
                (int)$submission->id
            )) {
                return true;
            }
        }

        return get_string(
            'nofileuploaded',
            'assignsubmission_externalstorage'
        );
    }

    /**
     * Get configured legacy local storage directory.
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
     * Resolve a legacy local file path safely.
     */
    private function get_absolute_path(string $storagekey) {
        $root = $this->get_storage_root();

        if (!$root) {
            return false;
        }

        if (!preg_match(
            '#^course_[0-9]+/assign_[0-9]+/submission_[0-9]+/[a-f0-9]{32}\.blob$#',
            $storagekey
        )) {
            return false;
        }

        $path = $root . DIRECTORY_SEPARATOR .
            str_replace('/', DIRECTORY_SEPARATOR, $storagekey);

        if (!is_file($path)) {
            return false;
        }

        $realpath = realpath($path);

        if (!$realpath ||
                strpos(
                    $realpath,
                    $root . DIRECTORY_SEPARATOR
                ) !== 0) {
            return false;
        }

        return $realpath;
    }

    /**
     * Save assignment submission to Azure Blob Storage.
     */
    public function save(stdClass $submission, stdClass $data) {
        global $DB;

        if (empty($submission->id)) {
            $this->set_error(
                get_string(
                    'uploadfailed',
                    'assignsubmission_externalstorage'
                )
            );
            return false;
        }

        $submissionid = (int)$submission->id;

        $upload = $_FILES[self::UPLOAD_ELEMENT] ?? null;

        // Keep the existing file when no replacement is uploaded.
        if (!$upload ||
                ($upload['error'] ?? UPLOAD_ERR_NO_FILE)
                === UPLOAD_ERR_NO_FILE) {

            if ($this->get_file_submission($submissionid)) {
                return true;
            }

            $this->set_error(
                get_string(
                    'nofileuploaded',
                    'assignsubmission_externalstorage'
                )
            );

            return false;
        }

        // Validate uploaded file.
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE)
                !== UPLOAD_ERR_OK ||
                empty($upload['tmp_name']) ||
                !is_uploaded_file($upload['tmp_name'])) {

            $this->set_error(
                get_string(
                    'uploadfailed',
                    'assignsubmission_externalstorage'
                )
            );

            return false;
        }

        // Validate filename.
        $originalname = str_replace(
            '\\',
            '/',
            $upload['name'] ?? ''
        );

        $filename = clean_param(
            basename($originalname),
            PARAM_FILE
        );

        if ($filename === '') {
            $this->set_error(
                get_string(
                    'invalidfilename',
                    'assignsubmission_externalstorage'
                )
            );

            return false;
        }

        $filesize = (int)$upload['size'];

        // Validate plugin maximum upload size.
        $maxbytes = (int)get_config(
            'assignsubmission_externalstorage',
            'maxbytes'
        );

        if ($maxbytes > 0 && $filesize > $maxbytes) {
            $this->set_error(
                get_string(
                    'filetoolarge',
                    'assignsubmission_externalstorage'
                )
            );

            return false;
        }

        // Assignment information.
        $cm = $this->assignment->get_course_module();

        $courseid = (int)$this->assignment->get_course()->id;
        $assignmentid = (int)$cm->instance;

        // Generate unpredictable blob name.
        try {
            $randomname = bin2hex(random_bytes(16)) . '.blob';
        } catch (\Throwable $e) {
            $this->set_error(
                get_string(
                    'uploadfailed',
                    'assignsubmission_externalstorage'
                )
            );
            return false;
        }

        $storagekey = "course_{$courseid}/assign_{$assignmentid}" .
            "/submission_{$submissionid}/{$randomname}";

        // Preserve old metadata until new upload succeeds.
        $oldrecord = $this->get_file_submission($submissionid);

        // Detect MIME type.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        $mimetype = $finfo->file($upload['tmp_name']);

        if (!$mimetype) {
            $mimetype = 'application/octet-stream';
        }

        // Upload to Azure.
        try {
            azure_blob_storage::upload(
                $upload['tmp_name'],
                $storagekey
            );
        } catch (\Throwable $e) {

    debugging(
        'Azure upload failed: ' . get_class($e) .
        ': ' . $e->getMessage() .
        ' in ' . $e->getFile() .
        ' on line ' . $e->getLine() .
        PHP_EOL . $e->getTraceAsString(),
        DEBUG_DEVELOPER
    );

    $this->set_error(
        get_string(
            'uploadfailed',
            'assignsubmission_externalstorage'
        )
    );

    return false;
}

        // Prepare database record.
        $now = time();

        $record = new \stdClass();

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

        // Store metadata after successful upload.
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

            // Remove newly uploaded blob if database update fails.
            try {
                azure_blob_storage::delete($storagekey);
            } catch (\Throwable $deleteexception) {
                debugging(
                    'Azure cleanup failed after database error.',
                    DEBUG_DEVELOPER
                );
            }

            debugging(
                'Submission metadata save failed: ' .
                    $e->getMessage(),
                DEBUG_DEVELOPER
            );

            $this->set_error(
                get_string(
                    'uploadfailed',
                    'assignsubmission_externalstorage'
                )
            );

            return false;
        }

        // Delete previous file only after successful replacement.
        if ($oldrecord &&
                $oldrecord->storagekey !== $storagekey) {

            $oldpath = $this->get_absolute_path(
                $oldrecord->storagekey
            );

            try {
                if ($oldpath && is_file($oldpath)) {
                    @unlink($oldpath);
                } else {
                    azure_blob_storage::delete(
                        $oldrecord->storagekey
                    );
                }
            } catch (\Throwable $e) {
                debugging(
                    'Old submission file cleanup failed.',
                    DEBUG_DEVELOPER
                );
            }
        }

        return true;
    }

    /**
     * Display submission summary and download link.
     */
    public function view_summary(
        stdClass $submission,
        &$showviewlink
    ) {
        $showviewlink = false;

        $record = $this->get_file_submission(
            (int)$submission->id
        );

        if (!$record) {
            return '';
        }

        $url = new moodle_url(
            '/mod/assign/submission/externalstorage/download.php',
            [
                'submissionid' => $submission->id
            ]
        );

        return html_writer::link(
            $url,
            s($record->filename),
            [
                'target' => '_blank',
                'rel' => 'noopener noreferrer'
            ]
        );
    }

    /**
     * Display submitted file.
     */
    public function view(stdClass $submissionorgrade) {
        if (empty($submissionorgrade->id)) {
            return '';
        }

        $showviewlink = false;

        return $this->view_summary(
            $submissionorgrade,
            $showviewlink
        );
    }

    /**
     * Remove a submission file.
     */
    public function remove(stdClass $submission) {
        global $DB;

        $record = $this->get_file_submission(
            (int)$submission->id
        );

        if (!$record) {
            return true;
        }

        $path = $this->get_absolute_path(
            $record->storagekey
        );

        try {
            if ($path && is_file($path)) {
                if (!unlink($path)) {
                    return false;
                }
            } else {
                azure_blob_storage::delete(
                    $record->storagekey
                );
            }
        } catch (\Throwable $e) {
            debugging(
                'Submission deletion failed: ' .
                    $e->getMessage(),
                DEBUG_DEVELOPER
            );

            return false;
        }

        $DB->delete_records(
            'assignsubmission_externalstorage',
            ['id' => $record->id]
        );

        return true;
    }

    /**
     * Delete all files belonging to an assignment.
     */
    public function delete_instance() {
        global $DB;

        $assignmentid = (int)$this->assignment
            ->get_course_module()->instance;

        $records = $DB->get_records(
            'assignsubmission_externalstorage',
            ['assignment' => $assignmentid]
        );

        foreach ($records as $record) {
            $path = $this->get_absolute_path(
                $record->storagekey
            );

            try {
                if ($path && is_file($path)) {
                    if (!unlink($path)) {
                        return false;
                    }
                } else {
                    azure_blob_storage::delete(
                        $record->storagekey
                    );
                }
            } catch (\Throwable $e) {
                debugging(
                    'Assignment file cleanup failed: ' .
                        $e->getMessage(),
                    DEBUG_DEVELOPER
                );

                return false;
            }
        }

        $DB->delete_records(
            'assignsubmission_externalstorage',
            ['assignment' => $assignmentid]
        );

        return true;
    }
}
