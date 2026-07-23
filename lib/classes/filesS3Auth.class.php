<?php

class filesS3Auth
{
    /**
     * @var string
     */
    protected $region;

    public function __construct($region = 'us-east-1')
    {
        $this->region = $region ?: 'us-east-1';
    }

    /**
     * Authenticate S3 request via AWS Signature V4/V2, HTTP Basic (access key/secret),
     * or an existing Webasyst session when no AWS credentials are present.
     *
     * Uses sessionless user binding so multipart UploadPart requests keep a stable
     * contact id without PHP session locks / headers-already-sent failures.
     *
     * When the request carries an AWS signature (Authorization / X-Amz-Signature),
     * SigV4/V2 is always verified — a Webasyst session cookie must not short-circuit.
     *
     * @return bool
     */
    public function authenticate()
    {
        if (self::hasRequestSignature()) {
            return $this->authenticateAwsSignature();
        }

        if ($this->authenticateAccessKeySecret()) {
            return true;
        }

        if (wa()->getUser()->isAuth()) {
            self::bindFilesAppUser(wa()->getUser());
            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    protected function authenticateAwsSignature()
    {
        $sig = $this->createSignatureVerifier();
        $access_key = $sig->getAccessKey();
        if (!$access_key) {
            return false;
        }

        return $this->bindContactByAccessKeySecret($access_key, null, $sig);
    }

    /**
     * Cyberduck / some S3 clients send Access Key + Secret as HTTP Basic
     * (PHP_AUTH_USER / PHP_AUTH_PW or Authorization: Basic …).
     *
     * @return bool
     */
    protected function authenticateAccessKeySecret()
    {
        $credentials = self::getAccessKeySecretFromRequest();
        if (!$credentials) {
            return false;
        }

        return $this->bindContactByAccessKeySecret($credentials['access_key'], $credentials['secret'], null);
    }

    /**
     * @param string $access_key
     * @param string|null $provided_secret null = verify via AWS signature object
     * @param filesS3SignatureV4|null $sig
     * @return bool
     */
    protected function bindContactByAccessKeySecret($access_key, $provided_secret, $sig)
    {
        $cm = new waContactModel();
        $contact = $cm->getByField('login', $access_key);
        if (!$contact) {
            return false;
        }

        $secret = filesS3Plugin::getSecretKey($contact['id']);
        if ($secret === '') {
            return false;
        }

        if ($sig !== null) {
            if (!$sig->verify($secret)) {
                return false;
            }
        } elseif (!hash_equals($secret, (string) $provided_secret)) {
            return false;
        }

        $user = new filesS3AuthUser($contact['id']);
        if (!$user->isAuth()) {
            return false;
        }

        self::bindFilesAppUser($user);
        return true;
    }

    /**
     * Access key / secret from HTTP Basic or PHP CGI auth variables.
     *
     * @return array{access_key:string,secret:string}|null
     */
    public static function getAccessKeySecretFromRequest()
    {
        $user = isset($_SERVER['PHP_AUTH_USER']) ? (string) $_SERVER['PHP_AUTH_USER'] : '';
        $pass = isset($_SERVER['PHP_AUTH_PW']) ? (string) $_SERVER['PHP_AUTH_PW'] : '';

        if ($user === '') {
            foreach (array(ifset($_SERVER['HTTP_AUTHORIZATION']), ifset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) as $header) {
                if (!$header || !preg_match('/^Basic\s+(\S+)/i', trim($header), $m)) {
                    continue;
                }
                $decoded = base64_decode($m[1], true);
                if ($decoded === false || strpos($decoded, ':') === false) {
                    continue;
                }
                list($user, $pass) = explode(':', $decoded, 2);
                break;
            }
        }

        $user = trim($user);
        if ($user === '' || $pass === '') {
            return null;
        }

        return array(
            'access_key' => $user,
            'secret'     => $pass,
        );
    }

    /**
     * Whether the request looks like S3 API (SigV4/V2, Basic keys, x-amz-*, S3 query).
     *
     * @return bool
     */
    public static function isS3ProtocolRequest()
    {
        if (self::hasRequestSignature()) {
            return true;
        }
        if (self::getAccessKeySecretFromRequest()) {
            return true;
        }
        if (waRequest::get('X-Amz-Algorithm') || waRequest::get('X-Amz-Credential') || waRequest::get('X-Amz-Signature')) {
            return true;
        }
        if (waRequest::get('list-type') !== null || waRequest::get('uploads') !== null || waRequest::get('uploadId') !== null) {
            return true;
        }
        if (waRequest::get('location') !== null || waRequest::get('delete') !== null) {
            return true;
        }
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_X_AMZ_') === 0 && $value !== '' && $value !== null) {
                return true;
            }
        }
        $copy = waRequest::server('HTTP_X_AMZ_COPY_SOURCE');
        if ($copy) {
            return true;
        }
        return false;
    }

    /**
     * Clear WebDAV traffic that must not be claimed by the S3 settlement.
     *
     * @return bool
     */
    public static function isWebDavProtocolRequest()
    {
        $method = strtoupper((string) waRequest::server('REQUEST_METHOD'));
        if (in_array($method, array('PROPFIND', 'PROPPATCH', 'MKCOL', 'LOCK', 'UNLOCK', 'REPORT'), true)) {
            return true;
        }
        // Depth is WebDAV; S3 uses x-amz-* for copy, not Destination alone with MOVE/COPY.
        if (waRequest::server('HTTP_DEPTH') !== null && waRequest::server('HTTP_DEPTH') !== '') {
            return true;
        }
        if (in_array($method, array('MOVE', 'COPY'), true) && !waRequest::server('HTTP_X_AMZ_COPY_SOURCE')) {
            return true;
        }
        return false;
    }

    /**
     * Whether the current request includes AWS S3 signature material.
     *
     * @return bool
     */
    public static function hasRequestSignature()
    {
        if (waRequest::get('X-Amz-Signature')) {
            return true;
        }

        $candidates = array(
            ifset($_SERVER['HTTP_AUTHORIZATION']),
            ifset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']),
        );

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strtolower($name) === 'authorization') {
                        $candidates[] = $value;
                    }
                }
            }
        }

        foreach ($candidates as $auth) {
            if (!$auth) {
                continue;
            }
            $auth = trim($auth);
            if (stripos($auth, filesS3SignatureV4::ALGORITHM) !== false) {
                return true;
            }
            if (preg_match('/^AWS\s+[^:]+:.+/i', $auth)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return filesS3SignatureV4
     */
    protected function createSignatureVerifier()
    {
        return new filesS3SignatureV4($this->region);
    }

    /**
     * Bind authenticated user for S3 and reset Files rights singleton.
     *
     * filesRights caches the first request user (often a guest from frontend
     * bootstrap). Without resetting it, limited/personal storages stay invisible
     * after sessionless setUser(). Kept inside the plugin to avoid Files app edits.
     *
     * @param waUser|waContact $user
     */
    public static function bindFilesAppUser($user)
    {
        wa()->setUser($user);
        if (method_exists($user, 'getLocale')) {
            wa()->setLocale($user->getLocale());
        }
        self::resetFilesRightsSingleton();
    }

    /**
     * Drop filesRights request singleton so the next inst() picks up wa()->getUser().
     */
    protected static function resetFilesRightsSingleton()
    {
        if (!class_exists('filesRights')) {
            return;
        }

        $ref = new ReflectionClass('filesRights');
        if (!$ref->hasProperty('instance')) {
            return;
        }

        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        if ($prop->isStatic()) {
            $prop->setValue(null, null);
        }
    }
}
