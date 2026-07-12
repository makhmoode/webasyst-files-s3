<?php

/**
 * Signature verifier without getAllHeaders() function-static cache (needed for PHPUnit).
 */
class FilesS3SignatureV4TestDouble extends filesS3SignatureV4
{
    /**
     * @return array
     */
    protected function getAllHeaders()
    {
        $headers = array();
        if (function_exists('getallheaders')) {
            $raw = @getallheaders();
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

        // Prefer current $_SERVER Authorization over stale getallheaders() values in CLI/tests.
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (empty($_SERVER['HTTP_AUTHORIZATION']) && empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            unset($headers['Authorization']);
        }

        return $headers;
    }
}
