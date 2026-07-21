<?php

class filesS3Server
{
    /**
     * @var array
     */
    protected $settings;

    /**
     * @var filesS3Backend
     */
    protected $backend;

    public function __construct($settings = array())
    {
        $this->settings = $settings;
        $this->backend = new filesS3Backend($settings);
    }

    public function request()
    {
        $this->discardBufferedOutput();

        $region = ifempty($this->settings['region'], filesS3Plugin::DEFAULT_REGION);
        $auth = new filesS3Auth($region);
        if (!$auth->authenticate()) {
            $this->sendProtocolHeaders();
            $this->error(403, 'AccessDenied', 'Access Denied');
            return;
        }

        $this->sendProtocolHeaders();
        $this->backend->init();

        $path = waRequest::server('REQUEST_URI');
        $relative = $this->backend->stripRootPath($path);
        list($bucket, $key) = $this->backend->splitBucketAndKey($relative);

        $method = strtoupper(waRequest::method());

        if ($method === 'GET' && waRequest::get('uploadId') && $key !== '') {
            $this->handleListParts($bucket, $key);
            return;
        }
        if ($method === 'GET' && $bucket !== '' && $key !== '' && $this->isListObjectsGetRequest($key)) {
            $this->applyListObjectsDefaults($key);
            $this->handleListObjects($bucket);
            return;
        }
        if ($method === 'GET' && $bucket !== '' && $key === '') {
            $this->handleListObjects($bucket);
            return;
        }
        if ($method === 'GET' && $bucket === '' && $key === '') {
            $this->handleListBuckets();
            return;
        }
        if ($method === 'GET' && $bucket !== '' && $key !== '') {
            $this->handleGetObject($bucket, $key);
            return;
        }
        if ($method === 'HEAD' && $bucket !== '' && $key !== '') {
            $this->handleHeadObject($bucket, $key);
            return;
        }
        if ($method === 'PUT' && waRequest::get('partNumber') && waRequest::get('uploadId') && $key !== '') {
            $this->handleUploadPart($bucket, $key);
            return;
        }
        if ($method === 'PUT' && $bucket !== '' && $key !== '') {
            $this->handlePutObject($bucket, $key);
            return;
        }
        if ($method === 'POST' && waRequest::get('delete') !== null && $bucket !== '' && $key === '') {
            $this->handleDeleteObjects($bucket);
            return;
        }
        if ($method === 'POST' && waRequest::get('uploads') !== null && $bucket !== '' && $key !== '') {
            $this->handleInitiateMultipartUpload($bucket, $key);
            return;
        }
        if ($method === 'POST' && waRequest::get('uploadId') && $key !== '') {
            $this->handleCompleteMultipartUpload($bucket, $key);
            return;
        }
        if ($method === 'DELETE' && waRequest::get('uploadId') && $key !== '') {
            $this->handleAbortMultipartUpload($bucket, $key);
            return;
        }
        if ($method === 'DELETE' && $bucket !== '' && $key !== '') {
            $this->handleDeleteObject($bucket, $key);
            return;
        }

        $this->error(405, 'MethodNotAllowed', 'The specified method is not allowed against this resource.');
    }

    protected function handleListBuckets()
    {
        $xml = filesS3Xml::listBuckets($this->backend->getBuckets());
        $this->xmlResponse(200, $xml);
    }

    protected function handleListObjects($bucket)
    {
        if (!$this->backend->bucketExists($bucket)) {
            $this->error(404, 'NoSuchBucket', 'The specified bucket does not exist', '/' . $bucket);
            return;
        }

        $prefix = (string) waRequest::get('prefix', '');
        $delimiter = (string) waRequest::get('delimiter', '');
        $max_keys = (int) waRequest::get('max-keys', 1000);
        $list_type = waRequest::get('list-type');
        $continuation = (string) waRequest::get('continuation-token', '');
        $marker = (string) waRequest::get('marker', $continuation);
        $start_after = (string) waRequest::get('start-after', $marker);

        $result = $this->backend->listObjects($bucket, $prefix, $delimiter, $max_keys, $start_after);
        if ($result === null) {
            $this->error(404, 'NoSuchBucket', 'The specified bucket does not exist', '/' . $bucket);
            return;
        }

        if ($list_type === '2') {
            $xml = filesS3Xml::listObjectsV2(
                $bucket,
                $prefix,
                $delimiter,
                $max_keys,
                $continuation,
                $result['items'],
                $result['common_prefixes'],
                $result['is_truncated'],
                $result['next']
            );
        } else {
            $xml = filesS3Xml::listObjectsV1(
                $bucket,
                $prefix,
                $delimiter,
                $max_keys,
                $marker,
                $result['items'],
                $result['common_prefixes'],
                $result['is_truncated'],
                $result['next']
            );
        }

        $this->xmlResponse(200, $xml);
    }

    protected function handleGetObject($bucket, $key)
    {
        if (!$this->backend->bucketExists($bucket)) {
            $this->error(404, 'NoSuchBucket', 'The specified bucket does not exist', '/' . $bucket . '/' . $key);
            return;
        }
        if (!$this->backend->resolveKey($bucket, $key)) {
            $this->error(404, 'NoSuchKey', 'The specified key does not exist.', '/' . $bucket . '/' . $key);
            return;
        }

        $range = waRequest::server('HTTP_RANGE');
        $offset = null;
        $length = null;
        $status = 200;
        if ($range && preg_match('/bytes=(\d+)-(\d*)/i', $range, $m)) {
            $offset = (int) $m[1];
            $head = $this->backend->headObject();
            if (!$head) {
                $this->error(404, 'NoSuchKey', 'The specified key does not exist.', '/' . $bucket . '/' . $key);
                return;
            }
            $total = $head['size'];
            $end = $m[2] !== '' ? (int) $m[2] : $total - 1;
            $length = $end - $offset + 1;
            $status = 206;
            header('Content-Range: bytes ' . $offset . '-' . $end . '/' . $total);
        }

        $object = $this->backend->getObject($offset, $length);
        if (!$object) {
            $this->error(404, 'NoSuchKey', 'The specified key does not exist.', '/' . $bucket . '/' . $key);
            return;
        }

        $this->prepareResponse();
        header('Content-Type: ' . $object['content_type']);
        header('Content-Length: ' . ($length !== null ? $length : $object['size']));
        header('ETag: "' . $object['etag'] . '"');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', strtotime($object['last_modified'])) . ' GMT');
        http_response_code($status);

        $stream = isset($object['stream']) ? $object['stream'] : null;
        if (is_resource($stream)) {
            if ($offset) {
                fseek($stream, $offset);
            }
            $remaining = $length !== null ? $length : $object['size'];
            while ($remaining > 0 && !feof($stream)) {
                $chunk = fread($stream, min(8192, $remaining));
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($stream);
        }
        $this->finishResponse();
    }

    protected function handleHeadObject($bucket, $key)
    {
        if (!$this->backend->bucketExists($bucket)) {
            $this->error(404, 'NoSuchBucket', 'The specified bucket does not exist', '/' . $bucket . '/' . $key);
            return;
        }
        if (!$this->backend->resolveKey($bucket, $key)) {
            $this->error(404, 'NoSuchKey', 'The specified key does not exist.', '/' . $bucket . '/' . $key);
            return;
        }

        $head = $this->backend->headObject();
        if (!$head) {
            $this->error(404, 'NoSuchKey', 'The specified key does not exist.', '/' . $bucket . '/' . $key);
            return;
        }

        $this->prepareResponse();
        header('Content-Type: ' . $head['content_type']);
        header('Content-Length: ' . $head['size']);
        header('ETag: "' . $head['etag'] . '"');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', strtotime($head['last_modified'])) . ' GMT');
        http_response_code(200);
        $this->finishResponse();
    }

    /**
     * ListObjects may use bucket root (?prefix=) or path-style GET of a folder key (…/folder/).
     *
     * @param string $key
     * @return bool
     */
    protected function isListObjectsGetRequest($key)
    {
        if (waRequest::get('list-type') !== null) {
            return true;
        }
        // Folder keys end with '/'; Cyberduck opens them with GET and expects ListBucketResult.
        return $key !== '' && substr($key, -1) === '/';
    }

    /**
     * @param string $key
     */
    protected function applyListObjectsDefaults($key)
    {
        if ((string) waRequest::get('prefix', '') === '') {
            $_GET['prefix'] = $key;
        }
        if (waRequest::get('list-type') === null || waRequest::get('list-type') === '') {
            $_GET['list-type'] = '2';
        }
        if ((string) waRequest::get('delimiter', '') === '') {
            $_GET['delimiter'] = '/';
        }
    }

    protected function handlePutObject($bucket, $key)
    {
        if (!$this->backend->bucketExists($bucket)) {
            $this->error(404, 'NoSuchBucket', 'The specified bucket does not exist', '/' . $bucket . '/' . $key);
            return;
        }

        $copy_source = waRequest::server('HTTP_X_AMZ_COPY_SOURCE');
        if (!$copy_source) {
            $sig = new filesS3SignatureV4(ifempty($this->settings['region'], filesS3Plugin::DEFAULT_REGION));
            $copy_source = $sig->getRequestHeader('x-amz-copy-source');
        }
        if ($copy_source) {
            $this->handleCopyObject($bucket, $key, $copy_source);
            return;
        }

        if (!$this->backend->resolveKey($bucket, $key)) {
            $this->error(404, 'NoSuchBucket', 'The specified bucket does not exist', '/' . $bucket . '/' . $key);
            return;
        }

        $body = $this->openUploadBody();
        if ($body === false) {
            $this->error(400, 'InvalidRequest', 'Malformed aws-chunked upload body.', '/' . $bucket . '/' . $key);
            return;
        }
        list($stream, $length) = $body;
        $ok = $this->backend->putObject($stream, $length);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if (!$ok) {
            $this->error(409, 'Conflict', 'Could not store object.', '/' . $bucket . '/' . $key);
            return;
        }

        $this->backend->resolveKey($bucket, $key);
        $head = $this->backend->headObject();
        $etag = $head ? $head['etag'] : md5(uniqid());
        header('ETag: "' . $etag . '"');
        http_response_code(200);
    }

    protected function handleCopyObject($bucket, $key, $copy_source)
    {
        $copy_source = $this->backend->normalizeCopySource($copy_source);
        if (strpos($copy_source, '/') === false) {
            $this->error(400, 'InvalidRequest', 'Invalid copy source.');
            return;
        }
        list($source_bucket, $source_key) = $this->backend->splitBucketAndKey($copy_source);
        if ($source_bucket === '') {
            $this->error(400, 'InvalidRequest', 'Invalid copy source.');
            return;
        }

        if (!$this->backend->bucketExists($bucket)) {
            $this->error(404, 'NoSuchBucket', 'The specified bucket does not exist', '/' . $bucket . '/' . $key);
            return;
        }
        if (!$this->backend->resolveKey($bucket, $key)) {
            $this->error(404, 'NoSuchBucket', 'The specified bucket does not exist', '/' . $bucket . '/' . $key);
            return;
        }

        if (!$this->backend->copyObject($source_bucket, $source_key)) {
            $this->error(404, 'NoSuchKey', 'The specified key does not exist.', '/' . $bucket . '/' . $key);
            return;
        }

        $this->backend->resolveKey($bucket, $key);
        $head = $this->backend->headObject();
        $etag = $head ? $head['etag'] : md5(uniqid());
        header('ETag: "' . $etag . '"');
        $this->xmlResponse(200, filesS3Xml::copyObjectResult($etag));
    }

    protected function handleDeleteObject($bucket, $key)
    {
        if (!$this->backend->bucketExists($bucket)) {
            $this->error(404, 'NoSuchBucket', 'The specified bucket does not exist', '/' . $bucket . '/' . $key);
            return;
        }
        if (!$this->backend->resolveKey($bucket, $key)) {
            $this->error(404, 'NoSuchBucket', 'The specified bucket does not exist', '/' . $bucket . '/' . $key);
            return;
        }

        $this->backend->deleteObject();
        $this->sendEmptyResponse(204);
    }

    protected function handleDeleteObjects($bucket)
    {
        if (!$this->backend->bucketExists($bucket)) {
            $this->error(404, 'NoSuchBucket', 'The specified bucket does not exist', '/' . $bucket);
            return;
        }

        $body = file_get_contents('php://input');
        $keys = array();
        if ($body) {
            $xml = @simplexml_load_string($body);
            if ($xml && isset($xml->Object)) {
                foreach ($xml->Object as $object) {
                    $keys[] = (string) $object->Key;
                }
            }
        }

        list($deleted, $errors) = $this->backend->deleteObjects($bucket, $keys);
        $xml = filesS3Xml::deleteObjects($deleted, $errors);
        $this->xmlResponse(200, $xml);
    }

    protected function handleInitiateMultipartUpload($bucket, $key)
    {
        if (!$this->backend->bucketExists($bucket)) {
            $this->error(404, 'NoSuchBucket', 'The specified bucket does not exist', '/' . $bucket . '/' . $key);
            return;
        }

        $upload_id = $this->backend->initiateMultipartUpload($bucket, $key);
        if (!$upload_id) {
            $this->error(409, 'Conflict', 'Could not initiate multipart upload.', '/' . $bucket . '/' . $key);
            return;
        }

        $xml = filesS3Xml::initiateMultipartUpload($bucket, $key, $upload_id);
        $this->xmlResponse(200, $xml);
    }

    protected function handleUploadPart($bucket, $key)
    {
        $upload_id = waRequest::get('uploadId');
        $part_number = (int) waRequest::get('partNumber');

        if (!$this->backend->bucketExists($bucket)) {
            $this->error(404, 'NoSuchBucket', 'The specified bucket does not exist', '/' . $bucket . '/' . $key);
            return;
        }

        $body = $this->openUploadBody();
        if ($body === false) {
            $this->error(400, 'InvalidRequest', 'Malformed aws-chunked upload body.', '/' . $bucket . '/' . $key);
            return;
        }
        list($stream, $length) = $body;
        $result = $this->backend->uploadPart($upload_id, $part_number, $stream, $length !== null ? (int) $length : 0);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if (!is_array($result) || empty($result['etag'])) {
            $this->multipartError($result, $bucket, $key);
            return;
        }

        header('ETag: "' . $result['etag'] . '"');
        http_response_code(200);
    }

    protected function handleCompleteMultipartUpload($bucket, $key)
    {
        $upload_id = waRequest::get('uploadId');
        $body = file_get_contents('php://input');
        $parts = array();
        if ($body) {
            $xml = @simplexml_load_string($body);
            if ($xml && isset($xml->Part)) {
                foreach ($xml->Part as $part) {
                    $etag = trim((string) $part->ETag, '"');
                    $parts[] = array(
                        'PartNumber' => (int) $part->PartNumber,
                        'ETag'       => $etag,
                    );
                }
            }
        }

        $result = $this->backend->completeMultipartUpload($upload_id, $parts);
        if (!is_array($result) || empty($result['etag'])) {
            $this->multipartError($result, $bucket, $key);
            return;
        }

        $xml = filesS3Xml::completeMultipartUpload($bucket, $key, $result['etag']);
        $this->xmlResponse(200, $xml);
    }

    protected function handleAbortMultipartUpload($bucket, $key)
    {
        $upload_id = waRequest::get('uploadId');
        $result = $this->backend->abortMultipartUpload($upload_id);
        if ($result !== true) {
            $this->multipartError(is_array($result) ? $result : array('error' => 'NoSuchUpload'), $bucket, $key);
            return;
        }
        http_response_code(204);
    }

    protected function handleListParts($bucket, $key)
    {
        $upload_id = waRequest::get('uploadId');
        $parts = $this->backend->listParts($upload_id);
        if ($parts === false || (is_array($parts) && isset($parts['error']))) {
            $this->multipartError(is_array($parts) ? $parts : array('error' => 'NoSuchUpload'), $bucket, $key);
            return;
        }

        $xml = filesS3Xml::listParts($bucket, $key, $upload_id, $parts);
        $this->xmlResponse(200, $xml);
    }

    /**
     * Map multipart backend error payload to S3 XML error response.
     *
     * @param array|false|null $result
     * @param string $bucket
     * @param string $key
     */
    protected function multipartError($result, $bucket, $key)
    {
        $error = is_array($result) ? ifset($result['error'], 'NoSuchUpload') : 'NoSuchUpload';
        $resource = '/' . $bucket . '/' . $key;
        if ($error === 'AccessDenied') {
            $this->error(403, 'AccessDenied', 'Access Denied', $resource);
            return;
        }
        if ($error === 'Conflict') {
            $this->error(409, 'Conflict', 'Could not store upload part.', $resource);
            return;
        }
        $this->error(404, 'NoSuchUpload', 'The specified upload does not exist.', $resource);
    }

    /**
     * Open upload body stream; decode aws-chunked framing when present.
     *
     * @return array|false array(resource $stream, int|null $length) or false on decode error
     */
    protected function openUploadBody()
    {
        $input = fopen('php://input', 'rb');
        if (!$input) {
            return false;
        }

        if (!filesS3ChunkedDecoder::isAwsChunkedRequest()) {
            $length = waRequest::server('CONTENT_LENGTH');
            $length = $length !== null && $length !== '' ? (int) $length : null;
            return array($input, $length);
        }

        $decoded = filesS3ChunkedDecoder::decode($input);
        fclose($input);
        if ($decoded === false) {
            return false;
        }

        list($stream, $decoded_length) = $decoded;
        $header_length = waRequest::server('HTTP_X_AMZ_DECODED_CONTENT_LENGTH');
        if ($header_length !== null && $header_length !== '' && (int) $header_length !== $decoded_length) {
            fclose($stream);
            return false;
        }

        return array($stream, $decoded_length);
    }

    protected function xmlResponse($code, $xml)
    {
        $this->sendResponse($code, $xml);
    }

    protected function error($code, $error_code, $message, $resource = '')
    {
        $this->sendResponse($code, filesS3Xml::error($error_code, $message, $resource));
    }

    protected function sendResponse($code, $body)
    {
        waLog::log(waRequest::server('REQUEST_URI') . "\n" . $body, 'files/s3-debug.log');
        $this->prepareResponse();
        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Length: ' . strlen($body));
        http_response_code($code);
        echo $body;
waLog::log(waRequest::server('REQUEST_URI') . "\n" . $body, 'files/s3-debug.log');
        $this->finishResponse();
    }

    protected function sendEmptyResponse($code)
    {
        $this->prepareResponse();
        http_response_code($code);
        $this->finishResponse();
    }

    protected function discardBufferedOutput()
    {
        $this->prepareResponse();
    }

    /**
     * S3 response protocol headers (safe to override in tests under PHPUnit).
     */
    protected function sendProtocolHeaders()
    {
        header('X-Amz-Request-Id: ' . substr(md5(uniqid('', true)), 0, 16));
        header('Server: Webasyst S3');
    }

    protected function prepareResponse()
    {
        @ini_set('display_errors', '0');
        while (@ob_get_level() > 0) {
            @ob_end_clean();
        }
    }

    protected function finishResponse()
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        die();
    }
}
