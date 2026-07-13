<?php

class filesS3PluginCredentialsManageController extends waJsonController
{
    public function execute()
    {
        if (!filesRights::inst()->isAdmin()) {
            throw new waRightsException(_wp('Access denied'));
        }

        $contact_id = waRequest::post('contact_id', 0, waRequest::TYPE_INT);
        $operation = waRequest::post('operation', '', waRequest::TYPE_STRING_TRIM);

        if (!$contact_id || !filesS3Plugin::userHasFilesAccess($contact_id)) {
            $this->errors[] = _wp('User not found or has no access to Files app');
            return;
        }

        switch ($operation) {
            case 'generate':
                if (filesS3Plugin::getSecretKey($contact_id)) {
                    $this->errors[] = _wp('Secret key already exists');
                    return;
                }
                $secret = filesS3Plugin::getSecretKey($contact_id, true);
                $this->response = array(
                    'has_secret' => true,
                    'secret_key' => $secret,
                );
                return;

            case 'regenerate':
                $secret = filesS3Plugin::regenerateSecretKey($contact_id);
                $this->response = array(
                    'has_secret' => true,
                    'secret_key' => $secret,
                );
                return;

            case 'delete':
                filesS3Plugin::deleteSecretKey($contact_id);
                $this->response = array(
                    'has_secret' => false,
                );
                return;

            default:
                $this->errors[] = _wp('Invalid operation');
        }
    }
}
