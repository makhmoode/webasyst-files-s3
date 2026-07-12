<?php

use PHPUnit\Framework\TestCase;

/**
 * Base TestCase for files/s3 plugin unit tests.
 * Saves and restores request globals between tests.
 */
abstract class FilesS3TestCase extends TestCase
{
    /**
     * @var array
     */
    protected $saved_server = array();

    /**
     * @var array
     */
    protected $saved_get = array();

    /**
     * @var array
     */
    protected $saved_post = array();

    /**
     * @var string|null
     */
    protected $saved_method = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->saved_server = $_SERVER;
        $this->saved_get = $_GET;
        $this->saved_post = $_POST;
        $this->saved_method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;
    }

    public function tearDown(): void
    {
        $_SERVER = $this->saved_server;
        $_GET = $this->saved_get;
        $_POST = $this->saved_post;
        if ($this->saved_method !== null) {
            $_SERVER['REQUEST_METHOD'] = $this->saved_method;
        }
        parent::tearDown();
    }

    /**
     * @param string $xml
     * @return SimpleXMLElement
     */
    protected function parseXml($xml)
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $this->assertInstanceOf(SimpleXMLElement::class, $doc, 'Invalid XML: ' . $xml);
        return $doc;
    }
}
