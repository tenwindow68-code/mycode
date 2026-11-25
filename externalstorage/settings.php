<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    $settings->add(new admin_setting_configtext(
        'assignsubmission_externalstorage/maxbytes',
        get_string('maxfilesize', 'assignsubmission_externalstorage'),
        get_string('maxfilesize_desc', 'assignsubmission_externalstorage'),
        '2147483647',
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'assignsubmission_externalstorage/azure_account_url',
        'Azure Storage Account URL',
        'Azure Blob Storage account endpoint.',
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'assignsubmission_externalstorage/azure_container',
        'Azure Container Name',
        'Azure Blob Storage container name.',
        'upes-lms-assignment',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'assignsubmission_externalstorage/azure_sas_token',
        'Azure SAS Token',
        'Container-level SAS token. Enter the token without the leading question mark.',
        ''
    ));
}

