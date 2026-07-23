<?php

/**
 * Test double for filesS3Server: capture response instead of die().
 */
class FilesS3ServerTestDouble extends filesS3Server
{
    /**
     * @var int|null
     */
    public $captured_code = null;

    /**
     * @var string
     */
    public $captured_body = '';

    /**
     * @var array
     */
    public $captured_headers = array();

    /**
     * @var bool
     */
    protected $capture_mode = true;

    /**
     * @param filesS3Backend|null $backend
     * @param array $settings
     */
    public function __construct($backend = null, array $settings = array())
    {
        parent::__construct($settings);
        if ($backend !== null) {
            $this->backend = $backend;
        }
    }

    /**
     * @param string $key
     * @return bool
     */
    public function exposeIsListObjectsGetRequest($key)
    {
        return $this->isListObjectsGetRequest($key);
    }

    /**
     * @param string $key
     */
    public function exposeApplyListObjectsDefaults($key)
    {
        $this->applyListObjectsDefaults($key);
    }

    /**
     * @return array|false
     */
    public function exposeOpenUploadBody()
    {
        return $this->openUploadBody();
    }

    protected function finishResponse()
    {
        if ($this->capture_mode) {
            return;
        }
        parent::finishResponse();
    }

    protected function sendProtocolHeaders()
    {
        if ($this->capture_mode) {
            return;
        }
        parent::sendProtocolHeaders();
    }

    protected function prepareResponse()
    {
        if ($this->capture_mode) {
            return;
        }
        parent::prepareResponse();
    }

    protected function sendResponse($code, $body)
    {
        if (!$this->capture_mode) {
            $this->prepareResponse();
        }
        $this->captured_code = $code;
        $this->captured_body = $body;
        $this->captured_headers['Content-Type'] = 'application/xml; charset=UTF-8';
        $this->captured_headers['Content-Length'] = strlen($body);
        if (!$this->capture_mode) {
            parent::sendResponse($code, $body);
        }
    }

    protected function sendEmptyResponse($code)
    {
        if (!$this->capture_mode) {
            $this->prepareResponse();
        }
        $this->captured_code = $code;
        $this->captured_body = '';
        if (!$this->capture_mode) {
            parent::sendEmptyResponse($code);
        }
    }

    /**
     * @param string $header
     */
    protected function emitHeader($header)
    {
        if ($this->capture_mode) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $this->captured_headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return;
        }
        parent::emitHeader($header);
    }

    /**
     * @return FilesS3BackendTestDouble|filesS3Backend
     */
    public function getBackend()
    {
        return $this->backend;
    }

    /**
     * Reset capture buffers.
     */
    public function resetCapture()
    {
        $this->captured_code = null;
        $this->captured_body = '';
        $this->captured_headers = array();
    }
}
