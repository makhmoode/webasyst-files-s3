<?php

class filesS3SignatureV4
{
    const ALGORITHM = 'AWS4-HMAC-SHA256';
    const SERVICE = 's3';

    /**
     * @var string
     */
    protected $region;

    public function __construct($region = 'us-east-1')
    {
        $this->region = $region ?: 'us-east-1';
    }

    /**
     * @return string|null
     */
    public function getAccessKey()
    {
        $auth = $this->getAuthorizationHeader();
        if ($auth && preg_match('/Credential=([^\/\s,]+)/', $auth, $m)) {
            return $m[1];
        }
        if ($auth && preg_match('/^AWS\s+([^:]+):/i', $auth, $m)) {
            return $m[1];
        }

        $key = waRequest::get('X-Amz-Credential');
        if ($key && preg_match('/^([^\/]+)/', $key, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param string $secret_key
     * @return bool
     */
    public function verify($secret_key)
    {
        if (waRequest::get('X-Amz-Signature')) {
            return $this->verifyPresigned($secret_key);
        }

        $auth = $this->getAuthorizationHeader();
        if (!$auth) {
            return false;
        }

        if (stripos($auth, self::ALGORITHM) !== false) {
            return $this->verifyV4($secret_key, $auth);
        }

        if (preg_match('/^AWS\s+[^:]+:.+/i', $auth)) {
            return $this->verifyV2($secret_key, $auth);
        }

        return false;
    }

    /**
     * @param string $secret_key
     * @param string $auth
     * @return bool
     */
    protected function verifyV4($secret_key, $auth)
    {
        if (!preg_match('/Credential=([^,]+)/', $auth, $cred_m)) {
            return false;
        }
        if (!preg_match('/SignedHeaders=([^,]+)/', $auth, $sh_m)) {
            return false;
        }
        if (!preg_match('/Signature=([a-f0-9]+)/i', $auth, $sig_m)) {
            return false;
        }

        $credential = $cred_m[1];
        $parts = explode('/', $credential);
        if (count($parts) < 5) {
            return false;
        }

        list($access_key, $date, $region, $service) = array_slice($parts, 0, 4);
        if ($service !== self::SERVICE) {
            return false;
        }

        $amz_date = waRequest::server('HTTP_X_AMZ_DATE');
        if (!$amz_date) {
            $amz_date = $this->getHeaderValue('x-amz-date');
        }
        if (!$amz_date) {
            return false;
        }

        $signed_headers = strtolower($sh_m[1]);
        $signature = strtolower($sig_m[1]);

        return $this->verifyCanonicalRequest(
            $secret_key,
            $date,
            $region,
            $amz_date,
            $signed_headers,
            $signature,
            $this->getCanonicalQueryString()
        );
    }

    /**
     * @param string $secret_key
     * @param string $auth
     * @return bool
     */
    protected function verifyV2($secret_key, $auth)
    {
        if (!preg_match('/^AWS\s+([^:]+):(.+)$/i', $auth, $m)) {
            return false;
        }

        $signature = trim($m[2]);
        $method = strtoupper(waRequest::server('REQUEST_METHOD', waRequest::method()));
        $content_md5 = $this->getHeaderValue('content-md5');
        $content_type = $this->getHeaderValue('content-type');
        $date = $this->getHeaderValue('date');
        if ($this->getHeaderValue('x-amz-date') !== '') {
            $date = '';
        }

        foreach ($this->getCanonicalizedResourceCandidatesV2() as $resource) {
            foreach ($this->getCanonicalizedAmzHeadersCandidatesV2() as $amz_headers) {
                $string_to_sign = $method . "\n"
                    . $content_md5 . "\n"
                    . $content_type . "\n"
                    . $date . "\n"
                    . $amz_headers
                    . $resource;

                $expected = base64_encode(hash_hmac('sha1', $string_to_sign, $secret_key, true));
                if (hash_equals($expected, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param string $secret_key
     * @return bool
     */
    protected function verifyPresigned($secret_key)
    {
        $algorithm = waRequest::get('X-Amz-Algorithm');
        $credential = waRequest::get('X-Amz-Credential');
        $signed_headers = strtolower(waRequest::get('X-Amz-SignedHeaders', ''));
        $signature = strtolower(waRequest::get('X-Amz-Signature', ''));
        $amz_date = waRequest::get('X-Amz-Date');

        if ($algorithm !== self::ALGORITHM || !$credential || !$signature || !$amz_date) {
            return false;
        }

        $expires = (int) waRequest::get('X-Amz-Expires', 0);
        if ($expires > 0) {
            $ts = strtotime(substr($amz_date, 0, 8) . 'T' . substr($amz_date, 9, 6) . 'Z');
            if ($ts && time() > $ts + $expires) {
                return false;
            }
        }

        $parts = explode('/', $credential);
        if (count($parts) < 5) {
            return false;
        }
        list($access_key, $date, $region, $service) = array_slice($parts, 0, 4);
        if ($service !== self::SERVICE) {
            return false;
        }

        $query = $_GET;
        unset($query['X-Amz-Signature']);
        $uri_qs = parse_url(waRequest::server('REQUEST_URI'), PHP_URL_QUERY);
        if ($uri_qs) {
            $query = $this->parseQueryString($uri_qs);
            unset($query['X-Amz-Signature']);
        }
        ksort($query);
        $canonical_query = $this->encodeQuery($query);

        $payload_hash = 'UNSIGNED-PAYLOAD';
        return $this->verifyCanonicalRequest(
            $secret_key,
            $date,
            $region,
            $amz_date,
            $signed_headers,
            $signature,
            $canonical_query,
            array($payload_hash)
        );
    }

    /**
     * @return string
     */
    protected function getPayloadHash()
    {
        $hash = $this->getHeaderValue('x-amz-content-sha256');
        if ($hash !== '') {
            return $hash;
        }
        return 'UNSIGNED-PAYLOAD';
    }

    /**
     * @return string[]
     */
    protected function getPayloadHashCandidates()
    {
        $candidates = array();
        $hash = $this->getHeaderValue('x-amz-content-sha256');
        if ($hash !== '') {
            if (preg_match('/^[a-f0-9]{64}$/i', $hash)) {
                $candidates[] = strtolower($hash);
            } else {
                $candidates[] = $hash;
            }
        }
        $candidates[] = 'UNSIGNED-PAYLOAD';
        $candidates[] = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
        return array_values(array_unique($candidates));
    }

    /**
     * @param string $name
     * @return string
     */
    public function getRequestHeader($name)
    {
        return $this->getHeaderValue($name);
    }

    /**
     * @param string $signed_headers
     * @return array[]
     */
    protected function getHeaderOverrideSets($signed_headers)
    {
        $sets = array(array());

        if (strpos($signed_headers, 'x-amz-copy-source') === false) {
            return $sets;
        }

        $raw = $this->getHeaderValue('x-amz-copy-source');
        $variants = array($raw);
        if ($raw !== '') {
            $decoded = rawurldecode($raw);
            if ($decoded !== $raw) {
                $variants[] = $decoded;
            }
            $encoded = $this->encodeCopySourcePath($raw);
            if ($encoded !== $raw) {
                $variants[] = $encoded;
            }
            if ($encoded !== $decoded) {
                $variants[] = $this->encodeCopySourcePath($decoded);
            }
            if (strpos($raw, '/') !== 0) {
                $variants[] = '/' . ltrim($raw, '/');
            }
            if (strpos($raw, '/') === 0) {
                $path = substr($raw, 1);
                $reencoded = '/' . str_replace('%2F', '/', rawurlencode(rawurldecode($path)));
                if ($reencoded !== $raw) {
                    $variants[] = $reencoded;
                }
            }
            if (strpos($raw, '/') !== false && strpos($raw, '%2F') === false) {
                $slash_encoded = str_replace('/', '%2F', $raw);
                if ($slash_encoded !== $raw) {
                    $variants[] = $slash_encoded;
                }
            }
            if (strpos($raw, '%2F') !== false) {
                $slash_decoded = str_replace('%2F', '/', $raw);
                if ($slash_decoded !== $raw) {
                    $variants[] = $slash_decoded;
                }
            }
            if (preg_match('#^https?://[^/]+(/.*)$#i', $raw, $m)) {
                $variants[] = $m[1];
                $variants[] = ltrim($m[1], '/');
            }
        }

        $sets = array();
        foreach (array_unique($variants) as $variant) {
            $sets[] = array('x-amz-copy-source' => $variant);
        }
        if (!$sets) {
            $sets[] = array();
        }

        return $sets;
    }

    /**
     * Encode copy source path per S3 URL-encoding rules (per path segment).
     *
     * @param string $value
     * @return string
     */
    protected function encodeCopySourcePath($value)
    {
        if ($value === '') {
            return $value;
        }

        $query = '';
        if (($pos = strpos($value, '?')) !== false) {
            $query = substr($value, $pos);
            $value = substr($value, 0, $pos);
        }

        $leading = strpos($value, '/') === 0 ? '/' : '';
        $path = ltrim($value, '/');
        if ($path === '') {
            return $leading . $query;
        }

        $segments = explode('/', $path);
        $encoded = array();
        foreach ($segments as $segment) {
            $encoded[] = rawurlencode(rawurldecode($segment));
        }

        return $leading . implode('/', $encoded) . $query;
    }

    /**
     * @param string $secret_key
     * @param string $date
     * @param string $region
     * @param string $amz_date
     * @param string $signed_headers
     * @param string $signature
     * @param string $canonical_query
     * @param string[]|null $payload_hashes
     * @return bool
     */
    protected function verifyCanonicalRequest($secret_key, $date, $region, $amz_date, $signed_headers, $signature, $canonical_query, $payload_hashes = null)
    {
        if ($payload_hashes === null) {
            $payload_hashes = $this->getPayloadHashCandidates();
        }

        $method = strtoupper(waRequest::server('REQUEST_METHOD', waRequest::method()));
        $signing_key = $this->getSigningKey($secret_key, $date, $region);

        foreach ($this->getCanonicalUriCandidates() as $canonical_uri) {
            foreach ($this->getHostHeaderCandidates() as $host) {
                foreach ($payload_hashes as $payload_hash) {
                    foreach ($this->getHeaderOverrideSets($signed_headers) as $header_overrides) {
                        $canonical_request = $this->buildCanonicalRequest(
                            $method,
                            $canonical_uri,
                            $canonical_query,
                            $this->getCanonicalHeaders($signed_headers, $host, $header_overrides),
                            $signed_headers,
                            $payload_hash
                        );

                        $string_to_sign = $this->buildStringToSign($amz_date, $date, $region, $canonical_request);
                        $expected = hash_hmac('sha256', $string_to_sign, $signing_key);

                        if (hash_equals($expected, $signature)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return string|null
     */
    protected function getAuthorizationHeader()
    {
        $candidates = array(
            ifset($_SERVER['HTTP_AUTHORIZATION']),
            ifset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']),
        );

        foreach ($this->getAllHeaders() as $name => $value) {
            if (strtolower($name) === 'authorization') {
                $candidates[] = $value;
            }
        }

        foreach ($candidates as $auth) {
            if (!$auth) {
                continue;
            }
            $auth = trim($auth);
            if (stripos($auth, self::ALGORITHM) !== false) {
                return $auth;
            }
            if (preg_match('/^AWS\s+[^:]+:.+/i', $auth)) {
                return $auth;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    protected function getCanonicalizedResourceCandidatesV2()
    {
        $uri = waRequest::server('REQUEST_URI');
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === false || $path === null || $path === '') {
            $path = '/';
        }

        $candidates = array();
        foreach (array_unique(array($path, rawurldecode($path), $this->createEncodedPath($path))) as $candidate) {
            $candidates[] = $this->appendCanonicalizedSubresourcesV2($candidate);
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param string $path
     * @return string
     */
    protected function appendCanonicalizedSubresourcesV2($path)
    {
        $uri = waRequest::server('REQUEST_URI');
        $qs = parse_url($uri, PHP_URL_QUERY);
        if (!$qs) {
            return $path;
        }

        $params = $this->parseQueryString($qs);
        $subresources = array(
            'acl', 'cors', 'delete', 'lifecycle', 'location', 'logging', 'notification',
            'partnumber', 'policy', 'requestpayment', 'torrent', 'uploadid', 'uploads',
            'versionid', 'versioning', 'versions', 'website',
        );

        $pairs = array();
        foreach ($params as $key => $value) {
            $lower = strtolower($key);
            if (!in_array($lower, $subresources, true)) {
                continue;
            }
            if (is_array($value)) {
                sort($value);
                foreach ($value as $v) {
                    $pairs[] = $lower . ($v !== '' ? '=' . $v : '');
                }
            } else {
                $pairs[] = $lower . ($value !== '' ? '=' . $value : '');
            }
        }

        if (!$pairs) {
            return $path;
        }

        sort($pairs);
        return $path . '?' . implode('&', $pairs);
    }

    /**
     * @return string[]
     */
    protected function getCanonicalizedAmzHeadersCandidatesV2()
    {
        $sets = array('' => array());

        $copy_source = $this->getHeaderValue('x-amz-copy-source');
        if ($copy_source !== '') {
            $variants = array($copy_source);
            foreach ($this->getHeaderOverrideSets('x-amz-copy-source') as $override) {
                if (isset($override['x-amz-copy-source'])) {
                    $variants[] = $override['x-amz-copy-source'];
                }
            }
            $sets = array();
            foreach (array_unique($variants) as $variant) {
                $sets[$variant] = array('x-amz-copy-source' => $variant);
            }
        }

        $result = array();
        foreach ($sets as $overrides) {
            $result[] = $this->buildCanonicalizedAmzHeadersV2($overrides);
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array $overrides
     * @return string
     */
    protected function buildCanonicalizedAmzHeadersV2($overrides = array())
    {
        $headers = array();
        foreach ($this->getAllHeaders() as $name => $value) {
            $lower = strtolower($name);
            if (strpos($lower, 'x-amz-') !== 0) {
                continue;
            }
            if (isset($overrides[$lower])) {
                $value = $overrides[$lower];
            }
            if (is_array($value)) {
                sort($value);
                $value = implode(',', $value);
            }
            $headers[$lower] = preg_replace('/\s+/', ' ', trim((string) $value));
        }

        foreach ($overrides as $name => $value) {
            if (!isset($headers[$name])) {
                $headers[$name] = preg_replace('/\s+/', ' ', trim((string) $value));
            }
        }

        ksort($headers);
        $lines = '';
        foreach ($headers as $name => $value) {
            $lines .= $name . ':' . $value . "\n";
        }

        return $lines;
    }

    /**
     * @return array
     */
    protected function getAllHeaders()
    {
        static $headers = null;
        if ($headers !== null) {
            return $headers;
        }

        $headers = array();
        if (function_exists('getallheaders')) {
            $raw = getallheaders();
            if (is_array($raw)) {
                foreach ($raw as $name => $value) {
                    $headers[$name] = $value;
                }
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                if (!isset($headers[$name])) {
                    $headers[$name] = $value;
                }
            }
        }

        if (!empty($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (!empty($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    /**
     * @return string[]
     */
    protected function getHostHeaderCandidates()
    {
        $host = $this->getHeaderValue('host');
        if ($host === '') {
            return array('');
        }

        $candidates = array($host);
        if (preg_match('/^(.+):443$/', $host, $m) && waRequest::isHttps()) {
            $candidates[] = $m[1];
        } elseif (preg_match('/^(.+):80$/', $host, $m) && !waRequest::isHttps()) {
            $candidates[] = $m[1];
        } elseif (strpos($host, ':') === false) {
            $candidates[] = $host . (waRequest::isHttps() ? ':443' : ':80');
        }

        return array_values(array_unique($candidates));
    }

    /**
     * S3 canonical URI candidates.
     * S3 does not double-encode paths; servers may pass REQUEST_URI encoded or decoded.
     *
     * @return string[]
     */
    protected function getCanonicalUriCandidates()
    {
        $uri = waRequest::server('REQUEST_URI');
        $raw_path = parse_url($uri, PHP_URL_PATH);
        if ($raw_path === false || $raw_path === null || $raw_path === '') {
            return array('/');
        }

        $candidates = array();
        $candidates[] = $this->normalizePath($raw_path);

        $decoded = rawurldecode($raw_path);
        if ($decoded !== $raw_path) {
            $candidates[] = $this->normalizePath($decoded);
        }

        $encoded = $this->createEncodedPath($decoded);
        $candidates[] = $encoded;

        return array_values(array_unique($candidates));
    }

    /**
     * @param string $path
     * @return string
     */
    protected function normalizePath($path)
    {
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }
        return $path === '' ? '/' : $path;
    }

    /**
     * AWS SigV4 path encoding for non-S3 services / fallback.
     *
     * @param string $path
     * @return string
     */
    protected function createEncodedPath($path)
    {
        $path = $this->normalizePath(rawurldecode($path));
        $encoded = rawurlencode(ltrim($path, '/'));
        return '/' . str_replace('%2F', '/', $encoded);
    }

    /**
     * @return string
     */
    protected function getRequestPath()
    {
        $uri = waRequest::server('REQUEST_URI');
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === false || $path === null || $path === '') {
            return '/';
        }
        return $this->normalizePath($path);
    }

    /**
     * @deprecated use getCanonicalUriCandidates()
     * @return string
     */
    protected function getCanonicalUri()
    {
        $candidates = $this->getCanonicalUriCandidates();
        return $candidates[0];
    }

    /**
     * @return string
     */
    protected function getCanonicalQueryString()
    {
        if (waRequest::get('X-Amz-Signature')) {
            $query = $_GET;
            unset($query['X-Amz-Signature']);
            ksort($query);
            return $this->encodeQuery($query);
        }

        $uri = waRequest::server('REQUEST_URI');
        $qs = parse_url($uri, PHP_URL_QUERY);
        if (!$qs) {
            return '';
        }

        $params = $this->parseQueryString($qs);
        ksort($params);
        return $this->encodeQuery($params);
    }

    /**
     * @param string $query_string
     * @return array
     */
    protected function parseQueryString($query_string)
    {
        $params = array();
        foreach (explode('&', $query_string) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $key = rawurldecode($parts[0]);
            $value = isset($parts[1]) ? rawurldecode(str_replace('+', ' ', $parts[1])) : '';
            if (isset($params[$key])) {
                if (!is_array($params[$key])) {
                    $params[$key] = array($params[$key]);
                }
                $params[$key][] = $value;
            } else {
                $params[$key] = $value;
            }
        }
        return $params;
    }

    /**
     * @param array $params
     * @return string
     */
    protected function encodeQuery($params)
    {
        $pairs = array();
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                sort($value);
                foreach ($value as $v) {
                    $pairs[] = $this->awsUriEncode($key) . '=' . $this->awsUriEncode((string) $v);
                }
            } else {
                $pairs[] = $this->awsUriEncode($key) . '=' . $this->awsUriEncode((string) $value);
            }
        }
        sort($pairs);
        return implode('&', $pairs);
    }

    /**
     * AWS SigV4 UriEncode.
     *
     * @param string $string
     * @return string
     */
    protected function awsUriEncode($string)
    {
        $result = '';
        $len = strlen($string);
        for ($i = 0; $i < $len; $i++) {
            $ch = $string[$i];
            if (
                ($ch >= 'A' && $ch <= 'Z')
                || ($ch >= 'a' && $ch <= 'z')
                || ($ch >= '0' && $ch <= '9')
                || $ch === '-' || $ch === '_' || $ch === '.' || $ch === '~'
            ) {
                $result .= $ch;
            } else {
                $result .= '%' . strtoupper(sprintf('%02X', ord($ch)));
            }
        }
        return $result;
    }

    /**
     * @param string $signed_headers
     * @param string|null $host_override
     * @param array $header_overrides
     * @return string
     */
    protected function getCanonicalHeaders($signed_headers, $host_override = null, $header_overrides = array())
    {
        $header_names = array_filter(explode(';', $signed_headers));
        $headers = array();

        foreach ($header_names as $name) {
            if (isset($header_overrides[$name])) {
                $value = $header_overrides[$name];
            } elseif ($name === 'host' && $host_override !== null) {
                $value = $host_override;
            } else {
                $value = $this->getHeaderValue($name);
            }
            $headers[$name] = preg_replace('/\s+/', ' ', trim($value));
        }

        ksort($headers);
        $lines = array();
        foreach ($headers as $name => $value) {
            $lines[] = $name . ':' . $value;
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * @param string $name
     * @return string
     */
    protected function getHeaderValue($name)
    {
        $name = strtolower($name);
        foreach ($this->getAllHeaders() as $header_name => $value) {
            if (strtolower($header_name) === $name) {
                if (is_array($value)) {
                    sort($value);
                    return implode(',', $value);
                }
                return (string) $value;
            }
        }

        if ($name === 'host') {
            return (string) waRequest::server('HTTP_HOST');
        }

        return '';
    }

    /**
     * @param string $method
     * @param string $canonical_uri
     * @param string $canonical_query
     * @param string $canonical_headers
     * @param string $signed_headers
     * @param string $payload_hash
     * @return string
     */
    protected function buildCanonicalRequest($method, $canonical_uri, $canonical_query, $canonical_headers, $signed_headers, $payload_hash)
    {
        return strtoupper($method) . "\n"
            . $canonical_uri . "\n"
            . $canonical_query . "\n"
            . $canonical_headers . "\n"
            . $signed_headers . "\n"
            . $payload_hash;
    }

    /**
     * @param string $amz_date
     * @param string $date
     * @param string $region
     * @param string $canonical_request
     * @return string
     */
    protected function buildStringToSign($amz_date, $date, $region, $canonical_request)
    {
        $scope = $date . '/' . $region . '/' . self::SERVICE . '/aws4_request';
        return self::ALGORITHM . "\n"
            . $amz_date . "\n"
            . $scope . "\n"
            . hash('sha256', $canonical_request);
    }

    /**
     * @param string $secret_key
     * @param string $date
     * @param string $region
     * @return string
     */
    protected function getSigningKey($secret_key, $date, $region)
    {
        $k_date = hash_hmac('sha256', $date, 'AWS4' . $secret_key, true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', self::SERVICE, $k_region, true);
        return hash_hmac('sha256', 'aws4_request', $k_service, true);
    }
}
