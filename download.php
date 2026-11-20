
<?php
// Download assignment submissions from Azure Blob Storage.

//require_once(__DIR__ . '/classes/local/azure_blob_storage.php');
require_once(__DIR__ . '/../../../../config.php');
use assignsubmission_externalstorage\local\azure_blob_storage;

$submissionid = required_param('submissionid', PARAM_INT);

// Get submission.
$submission = $DB->get_record(
    'assign_submission',
    ['id' => $submissionid],
    '*',
    MUST_EXIST
);

// Get assignment.
$assignment = $DB->get_record(
    'assign',
    ['id' => $submission->assignment],
    '*',
    MUST_EXIST
);

// Get course module.
$cm = get_coursemodule_from_instance(
    'assign',
    $assignment->id,
    $assignment->course,
    false,
    MUST_EXIST
);

// Verify login and permissions.
require_login($assignment->course, false, $cm);

$context = context_module::instance($cm->id);

require_capability('mod/assign:view', $context);

// Allow the owner or a user with grading permission.
if (!has_capability('mod/assign:grade', $context) &&
        (int)$submission->userid !== (int)$USER->id) {
    throw new required_capability_exception(
        $context,
        'mod/assign:view',
        'nopermissions',
        ''
    );
}

// Get Azure file metadata.
$record = $DB->get_record(
    'assignsubmission_externalstorage',
    ['submission' => $submissionid],
    '*',
    MUST_EXIST
);

// Validate the server-generated storage key.
if (!preg_match(
    '#^course_[0-9]+/assign_[0-9]+/submission_[0-9]+/[a-f0-9]{32}\.blob$#',
    $record->storagekey
)) {
    throw new moodle_exception('filenotfound');
}

// Get Azure blob as a stream.
try {
//    $stream = azure_blob_storage::download($record->storagekey);
$stream = \assignsubmission_externalstorage\local\azure_blob_storage::download(
    $record->storagekey
);
} catch (\Throwable $e) {
    debugging(
        'Azure assignment download failed: ' . $e->getMessage(),
        DEBUG_DEVELOPER
    );
    throw new moodle_exception('filenotfound');
}

if (!is_resource($stream)) {
    throw new moodle_exception('filenotfound');
}

// Create a temporary file outside the public web directory.
$tempdir = $CFG->tempdir;

if (!is_dir($tempdir) || !is_writable($tempdir)) {
    fclose($stream);
    throw new moodle_exception('filenotfound');
}

$tempfile = tempnam($tempdir, 'azureassign_');

if ($tempfile === false) {
    fclose($stream);
    throw new moodle_exception('filenotfound');
}

// Copy Azure stream into temporary file.
$destination = fopen($tempfile, 'wb');

if ($destination === false) {
    fclose($stream);
    @unlink($tempfile);
    throw new moodle_exception('filenotfound');
}

try {
    $copied = stream_copy_to_stream($stream, $destination);
} catch (\Throwable $e) {
    $copied = false;
} finally {
    fclose($stream);
    fclose($destination);
}

if ($copied === false ||
        (int)$copied !== (int)$record->filesize ||
        !is_readable($tempfile)) {
    @unlink($tempfile);
    throw new moodle_exception('filenotfound');
}

// Set safe file permissions.
@chmod($tempfile, 0600);

// Remove temporary file after Moodle finishes sending it.
register_shutdown_function(static function() use ($tempfile) {
    if (is_file($tempfile)) {
        @unlink($tempfile);
    }
});

// Determine MIME type.
$mimetype = !empty($record->mimetype)
    ? $record->mimetype
    : get_mimetype_for_sending($record->filename);

// Allow browser preview for selected file types.
$extension = strtolower(
    pathinfo($record->filename, PATHINFO_EXTENSION)
);

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

$openinline = in_array(
    $extension,
    $inlineextensions,
    true
);

// Stream the temporary file to the browser.
// Student-submitted content is not processed by Moodle filters.
send_file(
    $tempfile,
    clean_filename($record->filename),
    0,
    0,
    false,
    !$openinline,
    $mimetype
);
