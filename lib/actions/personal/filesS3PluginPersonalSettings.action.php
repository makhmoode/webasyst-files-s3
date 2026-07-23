<?php

class filesS3PluginPersonalSettingsAction extends waViewAction
{
    public function execute()
    {
        if (!wa()->getUser()->isAuth()) {
            throw new waRightsException(_wp('Access denied'));
        }

        $this->setTemplate('plugins/s3/templates/actions/personal/PersonalSettings.html');

        $user = wa()->getUser();
        $plugin = wa()->getPlugin('s3');
        $secret = filesS3Plugin::getSecretKey($user->getId(), true);

        $this->view->assign(array(
            'endpoint_url'   => filesS3Plugin::getEndpointUrl(),
            'server'         => filesS3Plugin::getEndpointServer(),
            'region'         => $plugin->getSettings('region'),
            'access_key'     => $user->get('login'),
            'secret_key'     => $secret,
            'regenerate_url' => wa()->getAppUrl('files') . '?plugin=s3&module=credentials&action=regenerate',
        ));
    }
}
