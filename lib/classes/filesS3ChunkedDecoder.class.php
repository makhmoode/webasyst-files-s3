<?php

/**
 * Decode AWS S3 aws-chunked request bodies (flexible checksums / streaming trailers).
 *
 * Wire format (simplified):
 *   <hex-size>[;chunk-signature=...]\r\n
 *   <payload>\r\n
 *   ...
 *   0\r\n
 *   <trailer headers>\r\n
 *   \r\n
 */
class filesS3ChunkedDecoder
{
    /**
     * Whether the current request body is aws-chunked.
     *
     * @return bool
     */
    public static function isAwsChunkedRequest()
    {
        $encoding = strtolower((string) waRequest::server('HTTP_CONTENT_ENCODING', ''));
        if (strpos($encoding, 'aws-chunked') !== false) {
            return true;
        }

        $decoded_length = waRequest::server('HTTP_X_AMZ_DECODED_CONTENT_LENGTH');
        if ($decoded_length !== null && $decoded_length !== '') {
            return true;
        }

        $payload_hash = strtolower((string) waRequest::server('HTTP_X_AMZ_CONTENT_SHA256', ''));
        return strpos($payload_hash, 'streaming-') === 0;
    }

    /**
     * Decode an aws-chunked body into a rewindable temp stream.
     *
     * @param resource $input
     * @return array|false array(stream, length) or false on framing error
     */
    public static function decode($input)
    {
        if (!is_resource($input)) {
            return false;
        }

        $out = fopen('php://temp', 'w+b');
        if (!$out) {
            return false;
        }

        $total = 0;
        while (true) {
            $header = self::readLine($input);
            if ($header === false) {
                fclose($out);
                return false;
            }

            $header = rtrim($header, "\r\n");
            if ($header === '') {
                continue;
            }

            $size_part = $header;
            $semi = strpos($header, ';');
            if ($semi !== false) {
                $size_part = substr($header, 0, $semi);
            }
            $size_part = trim($size_part);
            if ($size_part === '' || !ctype_xdigit($size_part)) {
                fclose($out);
                return false;
            }

            $chunk_size = hexdec($size_part);
            if ($chunk_size < 0) {
                fclose($out);
                return false;
            }

            if ($chunk_size === 0) {
                // Final chunk: consume trailers until blank line, do not write them.
                while (true) {
                    $trailer = self::readLine($input);
                    if ($trailer === false) {
                        break;
                    }
                    if ($trailer === "\r\n" || $trailer === "\n" || $trailer === '') {
                        break;
                    }
                }
                break;
            }

            $remaining = $chunk_size;
            while ($remaining > 0) {
                $piece = fread($input, min(8192, $remaining));
                if ($piece === false || $piece === '') {
                    fclose($out);
                    return false;
                }
                fwrite($out, $piece);
                $remaining -= strlen($piece);
                $total += strlen($piece);
            }

            // Chunk payload is followed by CRLF.
            $crlf = fread($input, 2);
            if ($crlf !== "\r\n") {
                // Tolerate lone LF in broken proxies.
                if ($crlf === "\n") {
                    // ok
                } elseif ($crlf === "\r") {
                    $next = fread($input, 1);
                    if ($next !== "\n") {
                        fclose($out);
                        return false;
                    }
                } else {
                    fclose($out);
                    return false;
                }
            }
        }

        rewind($out);
        return array($out, $total);
    }

    /**
     * @param resource $input
     * @return string|false
     */
    protected static function readLine($input)
    {
        $line = '';
        while (!feof($input)) {
            $ch = fread($input, 1);
            if ($ch === false || $ch === '') {
                break;
            }
            $line .= $ch;
            if ($ch === "\n") {
                return $line;
            }
            // Guard against runaway headers.
            if (strlen($line) > 8192) {
                return false;
            }
        }
        return $line === '' ? false : $line;
    }
}
