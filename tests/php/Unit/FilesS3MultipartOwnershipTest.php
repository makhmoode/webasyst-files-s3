<?php

class FilesS3MultipartOwnershipTest extends FilesS3TestCase
{
    public function testResolveOwnedUploadMissing()
    {
        $backend = new FilesS3BackendMultipartTestDouble();
        $backend->uploads = array();
        wa()->setUser(new filesS3AuthUserTestDouble(5));

        $result = $backend->resolveOwnedUpload('missing');
        $this->assertSame(array('error' => 'NoSuchUpload'), $result);
    }

    public function testResolveOwnedUploadWrongOwner()
    {
        $backend = new FilesS3BackendMultipartTestDouble();
        $backend->uploads['abc'] = array(
            'upload_id'  => 'abc',
            'contact_id' => 1,
            'storage_id' => 10,
            'parent_id'  => 0,
            'filename'   => 'a.bin',
        );
        wa()->setUser(new filesS3AuthUserTestDouble(5));

        $result = $backend->resolveOwnedUpload('abc');
        $this->assertSame(array('error' => 'AccessDenied'), $result);
    }

    public function testResolveOwnedUploadSameOwner()
    {
        $backend = new FilesS3BackendMultipartTestDouble();
        $backend->uploads['abc'] = array(
            'upload_id'  => 'abc',
            'contact_id' => 5,
            'storage_id' => 10,
            'parent_id'  => 0,
            'filename'   => 'a.bin',
        );
        wa()->setUser(new filesS3AuthUserTestDouble(5));

        $result = $backend->resolveOwnedUpload('abc');
        $this->assertArrayHasKey('upload', $result);
        $this->assertSame(5, (int) $result['upload']['contact_id']);
        $this->assertArrayHasKey('model', $result);
    }

    public function testCreateUploadFailsClosedWhenInsertDoesNotPersist()
    {
        $model = new FilesS3MultipartModelFailClosedDouble();
        $this->assertFalse($model->createUpload(5, 1, 0, 'a.bin'));
    }

    public function testCreateUploadRoundTripWhenPersistWorks()
    {
        $model = new FilesS3MultipartModelMemoryDouble();
        $upload_id = $model->createUpload(5, 1, 0, 'a.bin');
        $this->assertNotFalse($upload_id);
        $row = $model->getUpload($upload_id);
        $this->assertSame(5, (int) $row['contact_id']);
        $this->assertSame('a.bin', $row['filename']);
    }

    public function testWaFilesCreateTreatsExtensionlessPartPathAsDirectory()
    {
        $dir = sys_get_temp_dir() . '/files_s3_part_' . uniqid('', true);
        $part_path = $dir . '/1';
        @mkdir($dir, 0775, true);

        // Reproduces the UploadPart bug: create() without a dot makes a directory.
        waFiles::create($part_path);
        $this->assertTrue(is_dir($part_path));
        $this->assertFalse(@fopen($part_path, 'wb'));

        waFiles::delete($part_path);
        waFiles::create(dirname($part_path) . '/');
        $fh = fopen($part_path, 'wb');
        $this->assertTrue(is_resource($fh));
        fwrite($fh, 'x');
        fclose($fh);
        $this->assertTrue(is_file($part_path));

        waFiles::delete($dir);
    }

    public function testMultipartErrorMapsCodes()
    {
        $backend = new FilesS3BackendTestDouble(array(), '/files/');
        $server = new FilesS3ServerTestDouble($backend, array('region' => 'us-east-1'));

        $ref = new ReflectionClass($server);
        $method = $ref->getMethod('multipartError');
        $method->setAccessible(true);

        $server->resetCapture();
        $method->invoke($server, array('error' => 'AccessDenied'), 'b', 'k');
        $this->assertSame(403, $server->captured_code);
        $this->assertStringContainsString('AccessDenied', $server->captured_body);

        $server->resetCapture();
        $method->invoke($server, array('error' => 'Conflict'), 'b', 'k');
        $this->assertSame(409, $server->captured_code);

        $server->resetCapture();
        $method->invoke($server, array('error' => 'NoSuchUpload'), 'b', 'k');
        $this->assertSame(404, $server->captured_code);
    }
}

class FilesS3MultipartModelMemoryDouble extends filesS3MultipartModel
{
    /**
     * @var array
     */
    public $rows = array();

    public function __construct()
    {
        // Skip waModel DB bootstrap.
    }

    public function insert($data, $type = 0)
    {
        $this->rows[$data['upload_id']] = $data;
        return true;
    }

    public function getUpload($upload_id, $contact_id = null)
    {
        if (!isset($this->rows[$upload_id])) {
            return null;
        }
        $row = $this->rows[$upload_id];
        if ($contact_id !== null && (int) $row['contact_id'] !== (int) $contact_id) {
            return null;
        }
        return $row;
    }
}

class FilesS3MultipartModelFailClosedDouble extends FilesS3MultipartModelMemoryDouble
{
    public function insert($data, $type = 0)
    {
        return true;
    }

    public function getUpload($upload_id, $contact_id = null)
    {
        return null;
    }
}

class FilesS3BackendMultipartTestDouble extends FilesS3BackendTestDouble
{
    /**
     * @var array
     */
    public $uploads = array();

    /**
     * @return filesS3MultipartModel
     */
    protected function getMultipartModel()
    {
        $model = new FilesS3MultipartModelMemoryDouble();
        $model->rows = $this->uploads;
        return $model;
    }
}
