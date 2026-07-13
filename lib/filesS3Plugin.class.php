<?php

class filesS3Plugin extends waPlugin
{
    const CONTACT_SETTINGS_APP = 'files.s3';
    const SECRET_KEY_SETTING = 'secret_key';

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
                $settlements[$uri] = $url;
            }
        }
        $view = wa()->getView();
        $view->assign('settlements', $settlements);
        $view->assign('settlement', wa()->getPlugin('s3')->getSettings('settlement'));
        return $view->fetch('plugins/s3/templates/settlement.html');
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
