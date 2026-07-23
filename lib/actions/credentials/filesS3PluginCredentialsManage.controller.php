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
                $this->response = $this->buildCredentialsResponse($contact_id, $secret);
                return;

            case 'regenerate':
                $secret = filesS3Plugin::regenerateSecretKey($contact_id);
                $this->response = $this->buildCredentialsResponse($contact_id, $secret);
                return;

            case 'show':
                $secret = filesS3Plugin::getSecretKey($contact_id);
                if ($secret === '') {
                    $this->errors[] = _wp('Secret key is not configured');
                    return;
                }
                $this->response = $this->buildCredentialsResponse($contact_id, $secret);
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

    /**
     * @param int $contact_id
     * @param string $secret
     * @return array
     */
    protected function buildCredentialsResponse($contact_id, $secret)
    {
        $contact = new waContact($contact_id);
        $plugin = wa()->getPlugin('s3');

        return array(
            'has_secret'   => true,
            'endpoint_url' => filesS3Plugin::getEndpointUrl(),
            'server'       => filesS3Plugin::getEndpointServer(),
            'region'       => $plugin->getSettings('region'),
            'access_key'   => (string) $contact->get('login'),
            'secret_key'   => $secret,
        );
    }
}
