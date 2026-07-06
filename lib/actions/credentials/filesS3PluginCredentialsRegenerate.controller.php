<?php

class filesS3PluginCredentialsRegenerateController extends waJsonController
{
    public function execute()
    {
        if (!wa()->getUser()->isAuth()) {
            throw new waRightsException(_wp('Access denied'));
        }

        $secret = filesS3Plugin::regenerateSecretKey(wa()->getUser()->getId());
        $this->response = array(
            'secret_key' => $secret,
        );
    }
}
