<?php

class filesS3PluginBackendSidebarAction extends waViewAction
{
    public function execute()
    {
        $this->setTemplate('plugins/s3/templates/actions/backend/CredentialsSidebar.html');

        $user = wa()->getUser();
        $plugin = wa()->getPlugin('s3');
        $secret = filesS3Plugin::getSecretKey($user->getId(), true);

        $this->view->assign(array(
            'endpoint_url' => filesS3Plugin::getEndpointUrl(),
            'region'       => $plugin->getSettings('region') ?: 'us-east-1',
            'access_key'   => $user->get('login'),
            'secret_key'   => $secret,
            'regenerate_url' => wa()->getAppUrl('files') . '?plugin=s3&module=credentials&action=regenerate',
        ));
    }
}
