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
        header('X-Amz-Request-Id: ' . substr(md5(uniqid('', true)), 0, 16));
        header('Server: Webasyst S3');

        $region = ifempty($this->settings['region'], 'us-east-1');
        $auth = new filesS3Auth($region);
        if (!$auth->authenticate()) {
            $this->error(403, 'AccessDenied', 'Access Denied');
            return;
        }

        $this->backend->init();

        $path = waRequest::server('REQUEST_URI');
        $relative = $this->backend->stripRootPath($path);
        list($bucket, $key) = $this->backend->splitBucketAndKey($relative);

        $method = strtoupper(waRequest::method());

        if ($method === 'GET' && waRequest::get('uploadId') && $key !== '') {
            $this->handleListParts($bucket, $key);
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
        if ($method === 'POST' && waRequest::get('delete') && $bucket !== '' && $key === '') {
            $this->handleDeleteObjects($bucket);
            return;
        }
        if ($method === 'POST' && waRequest::get('uploads') && $bucket !== '' && $key !== '') {
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

        header('Content-Type: ' . $object['content_type']);
        header('Content-Length: ' . ($length !== null ? $length : $object['size']));
        header('ETag: "' . $object['etag'] . '"');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', strtotime($object['last_modified'])) . ' GMT');
        http_response_code($status);

        $stream = $object['stream'];
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
        if (is_resource($stream)) {
            fclose($stream);
        }
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

        header('Content-Type: ' . $head['content_type']);
        header('Content-Length: ' . $head['size']);
        header('ETag: "' . $head['etag'] . '"');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', strtotime($head['last_modified'])) . ' GMT');
        http_response_code(200);
    }

    protected function handlePutObject($bucket, $key)
    {
        if (!$this->backend->bucketExists($bucket)) {
            $this->error(404, 'NoSuchBucket', 'The specified bucket does not exist', '/' . $bucket . '/' . $key);
            return;
        }

        $copy_source = waRequest::server('HTTP_X_AMZ_COPY_SOURCE');
        if (!$copy_source) {
            $sig = new filesS3SignatureV4(ifempty($this->settings['region'], 'us-east-1'));
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

        $length = waRequest::server('CONTENT_LENGTH');
        $length = $length !== null && $length !== '' ? (int) $length : null;
        $stream = fopen('php://input', 'rb');
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

        $length = (int) waRequest::server('CONTENT_LENGTH');
        $stream = fopen('php://input', 'rb');
        $etag = $this->backend->uploadPart($upload_id, $part_number, $stream, $length);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if (!$etag) {
            $this->error(404, 'NoSuchUpload', 'The specified upload does not exist.', '/' . $bucket . '/' . $key);
            return;
        }

        header('ETag: "' . $etag . '"');
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
        if (!$result) {
            $this->error(404, 'NoSuchUpload', 'The specified upload does not exist.', '/' . $bucket . '/' . $key);
            return;
        }

        $xml = filesS3Xml::completeMultipartUpload($bucket, $key, $result['etag']);
        $this->xmlResponse(200, $xml);
    }

    protected function handleAbortMultipartUpload($bucket, $key)
    {
        $upload_id = waRequest::get('uploadId');
        if (!$this->backend->abortMultipartUpload($upload_id)) {
            $this->error(404, 'NoSuchUpload', 'The specified upload does not exist.', '/' . $bucket . '/' . $key);
            return;
        }
        http_response_code(204);
    }

    protected function handleListParts($bucket, $key)
    {
        $upload_id = waRequest::get('uploadId');
        $parts = $this->backend->listParts($upload_id);
        if ($parts === false) {
            $this->error(404, 'NoSuchUpload', 'The specified upload does not exist.', '/' . $bucket . '/' . $key);
            return;
        }

        $xml = filesS3Xml::listParts($bucket, $key, $upload_id, $parts);
        $this->xmlResponse(200, $xml);
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
        $this->prepareResponse();
        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Length: ' . strlen($body));
        http_response_code($code);
        echo $body;
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
