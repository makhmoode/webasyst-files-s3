<?php

class FilesS3ServerRoutingTest extends FilesS3TestCase
{
    /**
     * @var FilesS3ServerTestDouble
     */
    protected $server;

    public function setUp(): void
    {
        parent::setUp();
        $backend = new FilesS3BackendTestDouble(
            array(
                'docs' => array(
                    'id'              => 1,
                    'name'            => 'docs',
                    'create_datetime' => '2024-01-01 00:00:00',
                ),
            ),
            '/files/'
        );
        $this->server = new FilesS3ServerTestDouble($backend, array('region' => 'us-east-1'));
    }

    public function testIsListObjectsGetRequestByListType()
    {
        FilesS3RequestHelper::apply(array(
            'method' => 'GET',
            'uri'    => '/files/docs/folder/?list-type=2',
            'query'  => array('list-type' => '2'),
        ));
        $this->assertTrue($this->server->exposeIsListObjectsGetRequest('folder/'));
    }

    public function testIsListObjectsGetRequestByPrefixQueryOnFolderKey()
    {
        FilesS3RequestHelper::apply(array(
            'method' => 'GET',
            'uri'    => '/files/docs/folder/?prefix=folder/',
            'query'  => array('prefix' => 'folder/'),
        ));
        $this->assertTrue($this->server->exposeIsListObjectsGetRequest('folder/'));
    }

    public function testIsListObjectsGetRequestFalseForPlainObjectGet()
    {
        FilesS3RequestHelper::apply(array(
            'method' => 'GET',
            'uri'    => '/files/docs/file.txt',
        ));
        $this->assertFalse($this->server->exposeIsListObjectsGetRequest('file.txt'));
    }

    public function testApplyListObjectsDefaults()
    {
        FilesS3RequestHelper::apply(array(
            'method' => 'GET',
            'uri'    => '/files/docs/folder/',
        ));
        $_GET = array();

        $this->server->exposeApplyListObjectsDefaults('folder/');

        $this->assertSame('folder/', $_GET['prefix']);
        $this->assertSame('2', $_GET['list-type']);
        $this->assertSame('/', $_GET['delimiter']);
    }

    public function testApplyListObjectsDefaultsPreservesExistingPrefix()
    {
        FilesS3RequestHelper::apply(array(
            'method' => 'GET',
            'uri'    => '/files/docs/folder/?prefix=custom/&list-type=2&delimiter=/',
            'query'  => array(
                'prefix'    => 'custom/',
                'list-type' => '2',
                'delimiter' => '/',
            ),
        ));

        $this->server->exposeApplyListObjectsDefaults('folder/');

        $this->assertSame('custom/', $_GET['prefix']);
        $this->assertSame('2', $_GET['list-type']);
        $this->assertSame('/', $_GET['delimiter']);
    }

    public function testListBucketsRoutingHelperDoesNotDie()
    {
        // Smoke: ensure capture mode prevents die() on xml responses via protected path.
        $this->server->resetCapture();
        $ref = new ReflectionClass($this->server);
        $method = $ref->getMethod('xmlResponse');
        $method->setAccessible(true);
        $method->invoke($this->server, 200, filesS3Xml::listBuckets(array(
            array('name' => 'docs', 'create_datetime' => '2024-01-01 00:00:00'),
        )));

        $this->assertSame(200, $this->server->captured_code);
        $this->assertStringContainsString('ListAllMyBucketsResult', $this->server->captured_body);
        $this->assertStringContainsString('docs', $this->server->captured_body);
    }
}
