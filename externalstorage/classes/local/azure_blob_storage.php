<?php

namespace assignsubmission_externalstorage\local;

defined('MOODLE_INTERNAL') || die();

class azure_blob_storage {

    /**
     * Return Azure Blob client.
     *
     * @return BlobRestProxy
     */
    private static function get_client() {
    global $CFG;

    require_once($CFG->dirroot . '/../vendor/autoload.php');

    $accounturl = trim(get_config(
        'assignsubmission_externalstorage',
        'azure_account_url'
    ));

    $sas = trim(get_config(
        'assignsubmission_externalstorage',
        'azure_sas_token'
    ));

    if (empty($accounturl) || empty($sas)) {
        throw new \moodle_exception(
            'Azure configuration is incomplete'
        );
    }

    $accounturl = rtrim($accounturl, '/');
    $sas = ltrim($sas, '?');

    $host = parse_url($accounturl, PHP_URL_HOST);

    if (!$host ||
        !preg_match('/^([a-z0-9]+)\.blob\.core\.windows\.net$/i', $host, $matches)) {
        throw new \moodle_exception(
            'Invalid Azure Blob endpoint'
        );
    }

    $accountname = $matches[1];

   $connectionString =
    'BlobEndpoint=' . $accounturl . ';' .
    'SharedAccessSignature=' . $sas;
   $previousHandler = null;

$temporaryHandler = function(
    $severity,
    $message,
    $file,
    $line
) use (&$previousHandler) {

    // Ignore only the known PHP 8.4 SDK deprecation.
    if (
        ($severity === E_DEPRECATED ||
         $severity === E_USER_DEPRECATED) &&
        strpos(
            $file,
            '/vendor/microsoft/azure-storage-common/'
        ) !== false
    ) {
        return true;
    }

    if (is_callable($previousHandler)) {
        return $previousHandler(
            $severity,
            $message,
            $file,
            $line
        );
    }

    return false;
};

$previousHandler = set_error_handler($temporaryHandler);

try {
    $client =
        \MicrosoftAzure\Storage\Blob\BlobRestProxy::createBlobService(
            $connectionString
        );
} finally {
    restore_error_handler();
}

return $client;
}
    /**
     * Get configured container.
     *
     * @return string
     */
    private static function get_container() {
    $container = get_config(
        'assignsubmission_externalstorage',
        'azure_container'
    );

    if (empty($container)) {
        throw new \moodle_exception(
            'Azure container is not configured'
        );
    }

    return $container;
}

    /**
     * Upload a file to Azure.
     *
     * @param string $localpath Temporary uploaded file path.
     * @param string $blobname Azure blob name.
     * @return void
     */
    public static function upload(string $localpath, string $blobname): void {

        if (!is_readable($localpath)) {
            throw new \moodle_exception('Uploaded file is not readable.');
        }

        $client = self::get_client();
        $container = self::get_container();

        $stream = fopen($localpath, 'rb');

        if ($stream === false) {
            throw new \moodle_exception('Unable to open uploaded file.');
        }

        try {
            $client->createBlockBlob(
                $container,
                $blobname,
                $stream
            );
        } finally {
            //fclose($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Download a blob.
     *
     * @param string $blobname Azure blob name.
     * @return resource
     */
 public static function download(string $blobname) {

    $client = self::get_client();

    $container = self::get_container();

    try {

        $result = $client->getBlob(
            $container,
            $blobname
        );

        $stream = $result->getContentStream();

        if (!is_resource($stream)) {
            throw new \moodle_exception(
                'Unable to retrieve Azure blob stream'
            );
        }

        return $stream;

    } catch (\Throwable $e) {

        error_log(
            'Azure Blob Download Error: ' . $e->getMessage()
        );

        throw new \moodle_exception(
            'Unable to download file from Azure Storage'
        );
    }
}
    /**
     * Delete a blob.
     *
     * @param string $blobname Azure blob name.
     * @return void
     */
    public static function delete(string $blobname): void {
        $client = self::get_client();

        $client->deleteBlob(
            self::get_container(),
            $blobname
        );
    }
}
