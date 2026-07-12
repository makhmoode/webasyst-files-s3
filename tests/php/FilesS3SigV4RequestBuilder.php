<?php

/**
 * Builds AWS Signature V4 Authorization headers for unit tests.
 * Mirrors the encoding rules used by filesS3SignatureV4.
 */
class FilesS3SigV4RequestBuilder
{
    const ALGORITHM = 'AWS4-HMAC-SHA256';
    const SERVICE = 's3';
    const EMPTY_PAYLOAD_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /**
     * @param array $options
     *   - access_key, secret_key, region, method, uri, host
     *   - headers: extra headers (without Authorization)
     *   - payload: string body (default empty)
     *   - payload_hash: override (UNSIGNED-PAYLOAD or hex)
     *   - amz_date: Ymd\THis\Z
     *   - signed_headers: semicolon list (default host;x-amz-content-sha256;x-amz-date)
     * @return array{headers: array, amz_date: string, signature: string, authorization: string}
     */
    public static function sign(array $options)
    {
        $access_key = $options['access_key'];
        $secret_key = $options['secret_key'];
        $region = ifset($options['region'], 'us-east-1');
        $method = strtoupper(ifset($options['method'], 'GET'));
        $uri = ifset($options['uri'], '/');
        $host = ifset($options['host'], 'example.com');
        $headers = ifset($options['headers'], array());
        $payload = ifset($options['payload'], '');
        $amz_date = ifset($options['amz_date'], gmdate('Ymd\THis\Z'));
        $date = substr($amz_date, 0, 8);

        if (isset($options['payload_hash'])) {
            $payload_hash = $options['payload_hash'];
        } elseif ($payload === '') {
            $payload_hash = self::EMPTY_PAYLOAD_HASH;
        } else {
            $payload_hash = hash('sha256', $payload);
        }

        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === null || $path === false || $path === '') {
            $path = '/';
        }
        $qs = parse_url($uri, PHP_URL_QUERY);
        $query_params = array();
        if ($qs) {
            parse_str($qs, $query_params);
        }
        ksort($query_params);
        $canonical_query = self::encodeQuery($query_params);
        $canonical_uri = self::encodePath($path);

        $headers['host'] = $host;
        $headers['x-amz-date'] = $amz_date;
        $headers['x-amz-content-sha256'] = $payload_hash;

        if (isset($options['signed_headers'])) {
            $signed_headers = strtolower($options['signed_headers']);
        } else {
            $names = array_map('strtolower', array_keys($headers));
            sort($names);
            $signed_headers = implode(';', $names);
        }

        $canonical_headers = self::canonicalHeaders($signed_headers, $headers);
        $canonical_request = $method . "\n"
            . $canonical_uri . "\n"
            . $canonical_query . "\n"
            . $canonical_headers . "\n"
            . $signed_headers . "\n"
            . $payload_hash;

        $credential_scope = $date . '/' . $region . '/' . self::SERVICE . '/aws4_request';
        $string_to_sign = self::ALGORITHM . "\n"
            . $amz_date . "\n"
            . $credential_scope . "\n"
            . hash('sha256', $canonical_request);

        $signing_key = self::signingKey($secret_key, $date, $region);
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);

        $authorization = self::ALGORITHM
            . ' Credential=' . $access_key . '/' . $credential_scope
            . ', SignedHeaders=' . $signed_headers
            . ', Signature=' . $signature;

        $headers['Authorization'] = $authorization;

        return array(
            'headers'       => $headers,
            'amz_date'      => $amz_date,
            'signature'     => $signature,
            'authorization' => $authorization,
            'payload_hash'  => $payload_hash,
        );
    }

    /**
     * Build SigV2 Authorization header (AWS access:signature).
     *
     * @param array $options
     * @return array{headers: array, authorization: string}
     */
    public static function signV2(array $options)
    {
        $access_key = $options['access_key'];
        $secret_key = $options['secret_key'];
        $method = strtoupper(ifset($options['method'], 'GET'));
        $uri = ifset($options['uri'], '/');
        $host = ifset($options['host'], 'example.com');
        $headers = ifset($options['headers'], array());
        $date = ifset($options['date'], gmdate('D, d M Y H:i:s') . ' GMT');

        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === null || $path === false || $path === '') {
            $path = '/';
        }

        $content_md5 = ifset($headers['content-md5'], '');
        $content_type = ifset($headers['content-type'], '');
        $amz_lines = array();
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            if (strpos($lower, 'x-amz-') === 0) {
                $amz_lines[$lower] = $lower . ':' . trim(preg_replace('/\s+/', ' ', $value));
            }
        }
        ksort($amz_lines);
        $amz_headers = $amz_lines ? implode("\n", $amz_lines) . "\n" : '';

        $string_to_sign = $method . "\n"
            . $content_md5 . "\n"
            . $content_type . "\n"
            . $date . "\n"
            . $amz_headers
            . $path;

        $signature = base64_encode(hash_hmac('sha1', $string_to_sign, $secret_key, true));
        $authorization = 'AWS ' . $access_key . ':' . $signature;

        $headers['Date'] = $date;
        $headers['host'] = $host;
        $headers['Authorization'] = $authorization;

        return array(
            'headers'       => $headers,
            'authorization' => $authorization,
            'signature'     => $signature,
        );
    }

    /**
     * @param string $secret_key
     * @param string $date
     * @param string $region
     * @return string
     */
    public static function signingKey($secret_key, $date, $region)
    {
        $k_date = hash_hmac('sha256', $date, 'AWS4' . $secret_key, true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', self::SERVICE, $k_region, true);
        return hash_hmac('sha256', 'aws4_request', $k_service, true);
    }

    /**
     * @param string $path
     * @return string
     */
    public static function encodePath($path)
    {
        if ($path === '' || $path === '/') {
            return '/';
        }
        $leading = strpos($path, '/') === 0 ? '/' : '';
        $segments = explode('/', ltrim($path, '/'));
        $encoded = array();
        foreach ($segments as $segment) {
            $encoded[] = self::awsUriEncode(rawurldecode($segment));
        }
        return $leading . implode('/', $encoded);
    }

    /**
     * @param array $params
     * @return string
     */
    public static function encodeQuery(array $params)
    {
        $pairs = array();
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                sort($value);
                foreach ($value as $v) {
                    $pairs[] = self::awsUriEncode($key) . '=' . self::awsUriEncode((string) $v);
                }
            } else {
                $pairs[] = self::awsUriEncode($key) . '=' . self::awsUriEncode((string) $value);
            }
        }
        sort($pairs);
        return implode('&', $pairs);
    }

    /**
     * @param string $string
     * @return string
     */
    public static function awsUriEncode($string)
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
     * @param array $headers
     * @return string
     */
    protected static function canonicalHeaders($signed_headers, array $headers)
    {
        $lookup = array();
        foreach ($headers as $name => $value) {
            $lookup[strtolower($name)] = preg_replace('/\s+/', ' ', trim($value));
        }
        $names = array_filter(explode(';', $signed_headers));
        $lines = array();
        foreach ($names as $name) {
            $lines[] = $name . ':' . ifset($lookup[$name], '');
        }
        return implode("\n", $lines) . "\n";
    }
}
