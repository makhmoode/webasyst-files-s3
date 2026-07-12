<?php

/**
 * @group long_tests
 */
class FilesS3BackendCrudTest extends FilesS3IntegrationTestCase
{
    public function testPutGetHeadDeleteObject()
    {
        $bucket = self::$storage['name'];
        $key = 'crud_' . uniqid() . '.txt';
        $payload = 'hello-s3-' . uniqid();

        $backend = $this->createBackend();
        $this->assertTrue($backend->resolveKey($bucket, $key));

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $payload);
        rewind($stream);
        $this->assertTrue($backend->putObject($stream, strlen($payload)));
        fclose($stream);

        $backend->resolveKey($bucket, $key);
        $node = $backend->getRequestedNode();
        $this->assertNotEmpty($node);
        $this->assertSame('file', $node['type']);
        self::markForClean($node['id'], 'file');

        $head = $backend->headObject();
        $this->assertNotNull($head);
        $this->assertSame(strlen($payload), $head['size']);
        $this->assertNotEmpty($head['etag']);

        $object = $backend->getObject();
        $this->assertNotNull($object);
        $this->assertSame(strlen($payload), $object['size']);
        if (isset($object['stream']) && is_resource($object['stream'])) {
            $read = stream_get_contents($object['stream']);
            fclose($object['stream']);
            $this->assertSame($payload, $read);
        }

        $this->assertTrue($backend->deleteObject());

        $backend->resolveKey($bucket, $key);
        $after = $backend->getRequestedNode();
        $this->assertTrue(empty($after) || empty($after['id']) || (int) ifset($after['storage_id']) < 0);
    }

    public function testPutFolderViaTrailingSlashKey()
    {
        $bucket = self::$storage['name'];
        $key = 'new_folder_' . uniqid() . '/';

        $backend = $this->createBackend();
        $this->assertTrue($backend->resolveKey($bucket, $key));
        $this->assertTrue($backend->putObject(fopen('php://temp', 'r'), 0));

        $backend->resolveKey($bucket, $key);
        $node = $backend->getRequestedNode();
        $this->assertNotEmpty($node);
        $this->assertSame('folder', $node['type']);
        self::markForClean($node['id'], 'file');

        $head = $backend->headObject();
        $this->assertNotNull($head);
        $this->assertSame(0, $head['size']);
        $this->assertSame('application/x-directory', $head['content_type']);

        $object = $backend->getObject();
        $this->assertNotNull($object);
        $this->assertSame(0, $object['size']);
        $this->assertArrayNotHasKey('stream', $object);
    }

    public function testCopyObjectRenameInSameFolderWithoutSuffix()
    {
        $bucket = self::$storage['name'];
        $src_key = 'rename_src_' . uniqid() . '.txt';
        $dst_key = 'rename_dst_' . uniqid() . '.txt';
        $payload = 'rename-payload';

        $backend = $this->createBackend();
        $backend->resolveKey($bucket, $src_key);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $payload);
        rewind($stream);
        $this->assertTrue($backend->putObject($stream, strlen($payload)));
        fclose($stream);

        $backend->resolveKey($bucket, $src_key);
        $src = $backend->getRequestedNode();
        self::markForClean($src['id'], 'file');

        $dest = $this->createBackend();
        $dest->resolveKey($bucket, $dst_key);
        $this->assertTrue($dest->copyObject($bucket, $src_key));

        $dest->resolveKey($bucket, $dst_key);
        $copied = $dest->getRequestedNode();
        $this->assertNotEmpty($copied);
        $this->assertSame(basename($dst_key), $copied['name']);
        $this->assertStringNotContainsString('(1)', $copied['name']);
        self::markForClean($copied['id'], 'file');
    }

    public function testDeleteObjectIdempotentWhenMissing()
    {
        $bucket = self::$storage['name'];
        $key = 'missing_' . uniqid() . '.txt';

        $backend = $this->createBackend();
        $backend->resolveKey($bucket, $key);
        // No node — deleteObject should return false but not throw.
        $this->assertFalse($backend->deleteObject());
    }
}
