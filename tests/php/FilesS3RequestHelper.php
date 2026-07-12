<?php

/**
 * Build fake S3 HTTP request environment for unit tests.
 */
class FilesS3RequestHelper
{
    /**
     * @param array $options
     *   - method: string
     *   - uri: string (path + optional query)
     *   - host: string
     *   - headers: array name => value
     *   - query: array (merged into $_GET and URI query)
     */
    public static function apply(array $options = array())
    {
        $method = strtoupper(ifset($options['method'], 'GET'));
        $uri = ifset($options['uri'], '/');
        $host = ifset($options['host'], 'example.com');
        $headers = ifset($options['headers'], array());
        $query = ifset($options['query'], array());

        if ($query) {
            $qs = http_build_query($query);
            if (strpos($uri, '?') === false) {
                $uri .= '?' . $qs;
            } else {
                $uri .= '&' . $qs;
            }
        }

        $parsed_qs = parse_url($uri, PHP_URL_QUERY);
        $_GET = array();
        if ($parsed_qs) {
            parse_str($parsed_qs, $_GET);
        }
        if ($query) {
            $_GET = array_merge($_GET, $query);
        }

        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['SERVER_NAME'] = $host;
        $_SERVER['QUERY_STRING'] = $parsed_qs ?: '';

        unset(
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
            $_SERVER['HTTP_X_AMZ_DATE'],
            $_SERVER['HTTP_X_AMZ_CONTENT_SHA256'],
            $_SERVER['HTTP_X_AMZ_COPY_SOURCE'],
            $_SERVER['HTTP_X_AMZ_METADATA_DIRECTIVE'],
            $_SERVER['HTTP_CONTENT_MD5'],
            $_SERVER['CONTENT_TYPE'],
            $_SERVER['CONTENT_LENGTH'],
            $_SERVER['HTTP_DATE']
        );

        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            if ($lower === 'authorization') {
                $_SERVER['HTTP_AUTHORIZATION'] = $value;
            } elseif ($lower === 'content-type') {
                $_SERVER['CONTENT_TYPE'] = $value;
            } elseif ($lower === 'content-length') {
                $_SERVER['CONTENT_LENGTH'] = $value;
            } elseif ($lower === 'content-md5') {
                $_SERVER['HTTP_CONTENT_MD5'] = $value;
            } elseif ($lower === 'date') {
                $_SERVER['HTTP_DATE'] = $value;
            } elseif ($lower === 'host') {
                $_SERVER['HTTP_HOST'] = $value;
            } else {
                $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
                $_SERVER[$server_key] = $value;
            }
        }
    }

    /**
     * Reset $_GET / common S3 headers without restoring full $_SERVER.
     */
    public static function clearQuery()
    {
        $_GET = array();
        $_SERVER['QUERY_STRING'] = '';
    }
}
