<?php

class filesS3Plugin extends waPlugin
{
    const CONTACT_SETTINGS_APP = 'files.s3';
    const SECRET_KEY_SETTING = 'secret_key';
    const DEFAULT_REGION = 'server-1';

    public function getSettings($name = null)
    {
        $settings = parent::getSettings($name);

        if ($name === 'region') {
            return strlen((string) $settings) ? $settings : self::DEFAULT_REGION;
        }

        if ($name === null && is_array($settings) && !strlen((string) ifset($settings, 'region', ''))) {
            $settings['region'] = self::DEFAULT_REGION;
        }

        return $settings;
    }

    public function frontendRequest()
    {
        if (!$this->getSettings('enable')) {
            return false;
        }

        $url = parse_url(wa()->getRootUrl(true));
        $url = $url['host'] . wa()->getRequest()->server('REQUEST_URI');
        $settlement = preg_replace('/:\d+/', '', rtrim($this->getSettings('settlement'), '*'));

        if (stripos($url, $settlement) === false) {
            return false;
        }

        self::prepareS3Request();
        self::normalizeRequestHeaders();

        $server = new filesS3Server($this->getSettings());
        $server->request();

        self::finishS3Request();
    }

    /**
     * @return void
     */
    protected static function prepareS3Request()
    {
        @ini_set('display_errors', '0');
        while (@ob_get_level() > 0) {
            @ob_end_clean();
        }
    }

    /**
     * @return void
     */
    protected static function finishS3Request()
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        die();
    }

    public function backendSidebar($params)
    {
        if (!$this->getSettings('enable')) {
            return array();
        }

        $view = wa()->getView();
        $footer = $view->fetch(wa()->getAppPath('plugins/s3/templates/actions/backend/SidebarFooter.html', 'files'));

        return array(
            'footer' => $footer,
        );
    }

    public static function getEnableHtml()
    {
        $view = wa()->getView();
        $view->assign('enable', wa()->getPlugin('s3')->getSettings('enable'));
        return $view->fetch('plugins/s3/templates/enable.html');
    }

    public static function getSettlementHtml()
    {
        $routing = wa()->getRouting();
        $domain_routes = $routing->getByApp(wa()->getApp());
        $settlements = array();
        foreach ($domain_routes as $domain => $routes) {
            foreach ($routes as $route) {
                $uri = $domain . '/' . $route['url'];
                $url = 'http' . (waRequest::isHttps() ? 's' : '') . '://' . preg_replace('!\*$!', '', $uri);
                $settlements[$uri] = array(
                    'url'     => $url,
                    'is_root' => self::isRootSettlement($route),
                );
            }
        }

        $settlement = wa()->getPlugin('s3')->getSettings('settlement');
        if (empty($settlement) || empty($settlements[$settlement])) {
            foreach ($settlements as $uri => $item) {
                if ($item['is_root']) {
                    $settlement = $uri;
                    break;
                }
            }
        }

        $view = wa()->getView();
        $view->assign('settlements', $settlements);
        $view->assign('settlement', $settlement);
        return $view->fetch('plugins/s3/templates/settlement.html');
    }

    /**
     * @param array $route
     * @return bool
     */
    public static function isRootSettlement($route)
    {
        return ifset($route, 'url', '') === '*';
    }

    public static function getTopBlockHtml()
    {
        $view = wa()->getView();
        return $view->fetch('plugins/s3/templates/topBlock.html');
    }

    /**
     * @return string
     */
    public static function getEndpointUrl()
    {
        $plugin = wa()->getPlugin('s3');
        $settlement = $plugin->getSettings('settlement');
        if (!$settlement) {
            return '';
        }
        $settlement = preg_replace('!\*$!', '', $settlement);
        $url = 'http' . (waRequest::isHttps() ? 's' : '') . '://' . $settlement;

        return rtrim($url, '/');
    }

    /**
     * @param int $contact_id
     * @param bool $create_if_missing
     * @return string
     */
    public static function getSecretKey($contact_id, $create_if_missing = false)
    {
        $csm = new waContactSettingsModel();
        $secret = (string) $csm->getOne($contact_id, self::CONTACT_SETTINGS_APP, self::SECRET_KEY_SETTING);
        if ($secret === '' && $create_if_missing) {
            $secret = self::generateSecretKey();
            $csm->set($contact_id, self::CONTACT_SETTINGS_APP, self::SECRET_KEY_SETTING, $secret);
        }
        return $secret;
    }

    /**
     * @param int $contact_id
     * @return void
     */
    public static function deleteSecretKey($contact_id)
    {
        $csm = new waContactSettingsModel();
        $csm->delete($contact_id, self::CONTACT_SETTINGS_APP, self::SECRET_KEY_SETTING);
    }

    /**
     * @param int $contact_id
     * @return bool
     */
    public static function userHasFilesAccess($contact_id)
    {
        static $contact_ids = null;
        if ($contact_ids === null) {
            $contact_ids = array_flip((array) (new waContactRightsModel())->getUsers('files', 'backend', 1));
        }
        return isset($contact_ids[$contact_id]);
    }

    /**
     * @return array
     */
    public static function getFilesAppUsersWithSecrets()
    {
        $contact_ids = (new waContactRightsModel())->getUsers('files', 'backend', 1);
        if (!$contact_ids) {
            return array();
        }

        $users = (new waContactModel())->getById($contact_ids);
        if (!$users) {
            return array();
        }

        $csm = new waContactSettingsModel();
        $result = array();
        foreach ($users as $id => $user) {
            $contact = new waContact($user);
            $secret = (string) $csm->getOne($id, self::CONTACT_SETTINGS_APP, self::SECRET_KEY_SETTING);
            $result[] = array(
                'id'         => (int) $id,
                'name'       => $contact->getName(),
                'login'      => (string) $contact->get('login'),
                'photo_url'  => waContact::getPhotoUrl($id, $contact->get('photo'), 20),
                'has_secret' => $secret !== '',
            );
        }

        usort($result, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $result;
    }

    public static function getUsersSecretsBlockHtml()
    {
        if (!filesRights::inst()->isAdmin()) {
            return '';
        }

        $view = wa()->getView();
        $view->assign(array(
            'enable'     => wa()->getPlugin('s3')->getSettings('enable'),
            'users'      => self::getFilesAppUsersWithSecrets(),
            'manage_url' => wa()->getAppUrl('files') . '?plugin=s3&module=credentials&action=manage',
        ));
        return $view->fetch('plugins/s3/templates/usersSecrets.html');
    }

    /**
     * @param int $contact_id
     * @return string
     */
    public static function regenerateSecretKey($contact_id)
    {
        $secret = self::generateSecretKey();
        $csm = new waContactSettingsModel();
        $csm->set($contact_id, self::CONTACT_SETTINGS_APP, self::SECRET_KEY_SETTING, $secret);
        return $secret;
    }

    /**
     * @return string
     */
    public static function generateSecretKey()
    {
        return bin2hex(random_bytes(20));
    }

    /**
     * Normalize headers for SigV4 auth under CGI/FastCGI.
     */
    public static function normalizeRequestHeaders()
    {
        if (empty($_SERVER['HTTP_AUTHORIZATION']) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $lower = strtolower($name);
                if ($lower === 'authorization' && empty($_SERVER['HTTP_AUTHORIZATION'])) {
                    $_SERVER['HTTP_AUTHORIZATION'] = $value;
                }
                if ($lower === 'content-type' && empty($_SERVER['CONTENT_TYPE'])) {
                    $_SERVER['CONTENT_TYPE'] = $value;
                }
                if ($lower === 'content-length' && empty($_SERVER['CONTENT_LENGTH'])) {
                    $_SERVER['CONTENT_LENGTH'] = $value;
                }
                if (strpos($lower, 'x-amz-') === 0) {
                    $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
                    if (empty($_SERVER[$server_key])) {
                        $_SERVER[$server_key] = $value;
                    }
                }
            }
        }
    }
}
