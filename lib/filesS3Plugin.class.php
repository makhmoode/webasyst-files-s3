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

        // Dedicated S3 settlement: handle every request here (ListObjects GET may lack
        // detectable markers when Authorization is stripped). Leave clear WebDAV alone.
        if (filesS3Auth::isWebDavProtocolRequest()) {
            return false;
        }

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

        $has_root_settlement = false;
        foreach ($settlements as $item) {
            if (!empty($item['is_root'])) {
                $has_root_settlement = true;
                break;
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
        $view->assign('has_root_settlement', $has_root_settlement);
        $view->assign('example_s3_host', self::getExampleS3Host());
        return $view->fetch('plugins/s3/templates/settlement.html');
    }

    /**
     * Whether Files app has at least one root settlement usable as S3 endpoint.
     *
     * @return bool
     */
    public static function hasRootFilesSettlement()
    {
        $domain_routes = wa()->getRouting()->getByApp(wa()->getApp());
        foreach ($domain_routes as $routes) {
            foreach ($routes as $route) {
                if (self::isRootSettlement($route)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Example S3 host for settings hints (s3.<current-domain>).
     *
     * @return string
     */
    public static function getExampleS3Host()
    {
        $host = (string) waRequest::server('HTTP_HOST', '');
        $host = preg_replace('/:\d+$/', '', $host);
        $host = strtolower(trim($host));
        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            $host = 'example.com';
        }
        return 's3.'.$host;
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
     * Current PHP upload limits for the settings screen.
     *
     * @return array
     */
    public static function getUploadLimitsInfo()
    {
        $upload_raw = (string) ini_get('upload_max_filesize');
        $post_raw = (string) ini_get('post_max_size');
        $upload_bytes = waRequest::toBytes($upload_raw);
        $post_bytes = waRequest::getPostMaxSize();
        $effective_bytes = waRequest::getUploadMaxFilesize();

        return array(
            'upload_raw'          => $upload_raw,
            'post_raw'            => $post_raw,
            'upload_bytes'        => $upload_bytes,
            'post_bytes'          => $post_bytes,
            'effective_bytes'     => $effective_bytes,
            'upload_formatted'    => waFiles::formatSize($upload_bytes),
            'post_formatted'      => waFiles::formatSize($post_bytes),
            'effective_formatted' => waFiles::formatSize($effective_bytes),
            'php_ini'             => (string) php_ini_loaded_file(),
        );
    }

    public static function getUploadLimitsHtml()
    {
        $view = wa()->getView();
        $view->assign('upload_limits', self::getUploadLimitsInfo());
        return $view->fetch('plugins/s3/templates/uploadLimits.html');
    }

    /**
     * Storages available as S3 buckets for the current user.
     *
     * @return array
     */
    public static function getBucketsList()
    {
        $storage_model = new filesStorageModel();
        $buckets = array();
        foreach ($storage_model->getAvailableStorages() as $storage) {
            $name = (string) ifset($storage['name'], '');
            if ($name === '') {
                continue;
            }
            $access_type = (string) ifset($storage['access_type'], '');
            $buckets[] = array(
                'id'                 => (int) ifset($storage['id'], 0),
                'name'               => $name,
                'access_type'        => $access_type,
                'access_type_label'  => self::getStorageAccessTypeLabel($access_type),
                'dns_compatible'     => (bool) preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $name),
            );
        }

        usort($buckets, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return $buckets;
    }

    /**
     * @param string $access_type
     * @return string
     */
    public static function getStorageAccessTypeLabel($access_type)
    {
        switch ($access_type) {
            case filesStorageModel::ACCESS_TYPE_PERSONAL:
                return _wp('Personal');
            case filesStorageModel::ACCESS_TYPE_EVERYONE:
                return _wp('Shared with any backend user');
            case filesStorageModel::ACCESS_TYPE_LIMITED:
                return _wp('Limited access');
            default:
                return $access_type;
        }
    }

    public static function getBucketsHtml()
    {
        $view = wa()->getView();
        $view->assign('buckets', self::getBucketsList());
        return $view->fetch('plugins/s3/templates/buckets.html');
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
        // Always read from DB. waContactSettingsModel::getOne() short-circuits to
        // wa()->getUser()->getSettings() when contact_id matches the current user;
        // on frontend that cache often lacks files.s3 secrets and yields false 403.
        $row = $csm->getByField(array(
            'contact_id' => $contact_id,
            'app_id'     => self::CONTACT_SETTINGS_APP,
            'name'       => self::SECRET_KEY_SETTING,
        ));
        $secret = $row ? (string) $row['value'] : '';
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

        $result = array();
        foreach ($users as $id => $user) {
            $contact = new waContact($user);
            $secret = self::getSecretKey($id);
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
        self::recoverAuthorizationHeader();

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (!is_string($value) || $value === '') {
                    continue;
                }
                $lower = strtolower($name);
                if ($lower === 'authorization' && empty($_SERVER['HTTP_AUTHORIZATION'])) {
                    $_SERVER['HTTP_AUTHORIZATION'] = $value;
                }
                if ($lower === 'content-type' && empty($_SERVER['CONTENT_TYPE'])) {
                    $_SERVER['CONTENT_TYPE'] = $value;
                }
                if ($lower === 'content-length' && (!isset($_SERVER['CONTENT_LENGTH']) || $_SERVER['CONTENT_LENGTH'] === '')) {
                    $_SERVER['CONTENT_LENGTH'] = $value;
                }
                if ($lower === 'content-encoding' && empty($_SERVER['HTTP_CONTENT_ENCODING'])) {
                    $_SERVER['HTTP_CONTENT_ENCODING'] = $value;
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

    /**
     * Copy Authorization into HTTP_AUTHORIZATION from CGI/redirect/apache variants.
     */
    public static function recoverAuthorizationHeader()
    {
        // Drop non-string / empty Authorization placeholders (proxies sometimes leave NULL).
        foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $key) {
            if (!array_key_exists($key, $_SERVER)) {
                continue;
            }
            if (!is_string($_SERVER[$key]) || trim($_SERVER[$key]) === '') {
                unset($_SERVER[$key]);
            }
        }

        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return;
        }

        $candidates = array();
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (stripos($key, 'AUTHORIZATION') !== false) {
                $candidates[] = $value;
            }
        }

        foreach (array('getallheaders', 'apache_request_headers') as $fn) {
            if (!function_exists($fn)) {
                continue;
            }
            $headers = @$fn();
            if (!is_array($headers)) {
                continue;
            }
            foreach ($headers as $name => $value) {
                if (strtolower($name) === 'authorization' && is_string($value) && $value !== '') {
                    $candidates[] = $value;
                }
            }
        }

        foreach ($candidates as $auth) {
            $auth = trim($auth);
            if ($auth === '') {
                continue;
            }
            // AWS SigV4 / SigV2, or HTTP Basic (access key:secret) used by some S3 clients.
            if (
                stripos($auth, filesS3SignatureV4::ALGORITHM) !== false
                || preg_match('/^AWS\s+[^:]+:.+/i', $auth)
                || preg_match('/^Basic\s+\S+/i', $auth)
            ) {
                $_SERVER['HTTP_AUTHORIZATION'] = $auth;
                return;
            }
        }
    }
}
