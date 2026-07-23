<?php

class FilesS3BackendPathTest extends FilesS3TestCase
{
    /**
     * @var FilesS3BackendTestDouble
     */
    protected $backend;

    public function setUp(): void
    {
        parent::setUp();
        $this->backend = new FilesS3BackendTestDouble(
            array(
                'docs' => array(
                    'id'              => 1,
                    'name'            => 'docs',
                    'create_datetime' => '2024-01-01 00:00:00',
                ),
                'docs-archive' => array(
                    'id'              => 2,
                    'name'            => 'docs-archive',
                    'create_datetime' => '2024-01-02 00:00:00',
                ),
                'Мои файлы' => array(
                    'id'              => 3,
                    'name'            => 'Мои файлы',
                    'create_datetime' => '2024-01-03 00:00:00',
                ),
            ),
            '/files/',
            array('settlement' => 'example.com/*')
        );
    }

    public function testStripRootPathRemovesSettlementAndQuery()
    {
        $this->assertSame('docs/a.txt', $this->backend->stripRootPath('/files/docs/a.txt?list-type=2'));
        $this->assertSame('docs/a.txt', $this->backend->stripRootPath('/files/docs/a.txt'));
        $this->assertSame('docs', $this->backend->stripRootPath('/files/docs'));
    }

    public function testStripRootPathDecodesUrlEncoding()
    {
        $encoded = '/files/' . rawurlencode('Мои файлы') . '/' . rawurlencode('файл.txt');
        $this->assertSame('Мои файлы/файл.txt', $this->backend->stripRootPath($encoded));
    }

    public function testSplitBucketAndKeyLongestMatch()
    {
        $this->assertSame(array('docs', 'a.txt'), $this->backend->splitBucketAndKey('docs/a.txt'));
        $this->assertSame(array('docs-archive', 'x'), $this->backend->splitBucketAndKey('docs-archive/x'));
        $this->assertSame(array('docs', ''), $this->backend->splitBucketAndKey('docs'));
        $this->assertSame(array('', ''), $this->backend->splitBucketAndKey(''));
    }

    public function testSplitBucketAndKeyCyrillic()
    {
        list($bucket, $key) = $this->backend->splitBucketAndKey('Мои файлы/папка/файл.txt');
        $this->assertSame('Мои файлы', $bucket);
        $this->assertSame('папка/файл.txt', $key);
    }

    public function testSettlementPathForcesBucketName()
    {
        $backend = new FilesS3BackendTestDouble(
            array(
                'docs' => array(
                    'id'              => 10,
                    'name'            => 'docs',
                    'create_datetime' => '2024-01-01 00:00:00',
                ),
                'other' => array(
                    'id'              => 11,
                    'name'            => 'other',
                    'create_datetime' => '2024-01-02 00:00:00',
                ),
            ),
            '/files/',
            array('settlement' => 'example.com/files/*')
        );

        $this->assertSame('files', $backend->getSettlementBucketName());
        $this->assertTrue($backend->isSettlementBucketMode());
        $this->assertTrue($backend->bucketExists('files'));
        $this->assertFalse($backend->bucketExists('docs'));

        $this->assertSame(array('files', ''), $backend->parsePathStyleRequest('/files/'));
        $this->assertSame(array('files', ''), $backend->parsePathStyleRequest('/files'));
        $this->assertSame(array('files', 'docs/'), $backend->parsePathStyleRequest('/files/docs/'));
        $this->assertSame(array('files', 'docs'), $backend->parsePathStyleRequest('/files/docs'));
        $this->assertSame(array('files', 'docs/a.txt'), $backend->parsePathStyleRequest('/files/docs/a.txt'));
        // Path-style clients may repeat the bucket after the settlement path.
        $this->assertSame(array('files', 'docs/a.txt'), $backend->parsePathStyleRequest('/files/files/docs/a.txt'));
        $this->assertSame(array('files', 'docs/'), $backend->parsePathStyleRequest('/files/files/docs/'));

        $this->assertSame(array('docs', 'a.txt'), $backend->splitSettlementObjectKey('docs/a.txt'));
        $this->assertSame(array('docs', ''), $backend->splitSettlementObjectKey('docs'));
        $this->assertSame(array('docs', 'folder/x'), $backend->splitSettlementObjectKey('docs/folder/x'));

        $names = array_column($backend->getBuckets(), 'name');
        $this->assertSame(array('files'), $names);

        $listed = $backend->listObjects('files', '', '/');
        $this->assertNotNull($listed);
        $this->assertSame(array('docs/', 'other/'), $listed['common_prefixes']);
        $this->assertSame(array(), $listed['items']);
    }

    public function testSettlementInitRootUrlUsesSettlementPathNotRequestPath()
    {
        $real = new filesS3BackendSettlementRootUrlProbe(array('settlement' => 'example.com/files/*'));
        $real->initRootUrl();
        $this->assertSame('/files/', $real->exposeRootUrl());

        $this->assertSame('docs/a.txt', $real->stripRootPath('/files/docs/a.txt'));
        $this->assertSame('docs/', $real->stripRootPath('/files/docs/'));
        $this->assertSame('', $real->stripRootPath('/files/'));
        $this->assertSame(array('files', 'docs/'), $real->splitBucketAndKey('docs/'));
    }

    public function testGetSettlementPathHelper()
    {
        $this->assertSame('', filesS3Plugin::getSettlementPath('s3.example.com/*'));
        $this->assertSame('', filesS3Plugin::getSettlementPath('s3.example.com/'));
        $this->assertSame('files', filesS3Plugin::getSettlementPath('example.com/files/*'));
        $this->assertSame('files', filesS3Plugin::getSettlementPath('example.com/files/'));
        $this->assertSame('app/files', filesS3Plugin::getSettlementPath('example.com/app/files/*'));
        $this->assertTrue(filesS3Plugin::isRootSettlementSetting('s3.example.com/*'));
        $this->assertFalse(filesS3Plugin::isRootSettlementSetting('example.com/files/*'));
    }

    public function testDecodeObjectPath()
    {
        $this->assertSame('a b', $this->backend->decodeObjectPath('a%20b'));
        $this->assertSame('plain', $this->backend->decodeObjectPath('plain'));
        $this->assertSame('', $this->backend->decodeObjectPath(''));
    }

    public function testNormalizeCopySource()
    {
        $this->assertSame('docs/a.txt', $this->backend->normalizeCopySource('/docs/a.txt'));
        $this->assertSame('docs/a.txt', $this->backend->normalizeCopySource('docs/a.txt'));
        $this->assertSame(
            'docs/a.txt',
            $this->backend->normalizeCopySource('https://example.com/files/docs/a.txt')
        );
        $this->assertSame(
            'docs/a b.txt',
            $this->backend->normalizeCopySource('/docs/' . rawurlencode('a b.txt'))
        );
    }

    public function testBucketExistsAndGetBuckets()
    {
        $this->assertTrue($this->backend->bucketExists('docs'));
        $this->assertFalse($this->backend->bucketExists('missing'));

        $buckets = $this->backend->getBuckets();
        $names = array_column($buckets, 'name');
        $this->assertContains('docs', $names);
        $this->assertContains('docs-archive', $names);
        $this->assertCount(3, $buckets);
    }

    public function testGetEtagStable()
    {
        $node = array(
            'id'              => 42,
            'size'            => 100,
            'update_datetime' => '2024-01-01 12:00:00',
            'create_datetime' => '2024-01-01 10:00:00',
        );
        $etag = $this->backend->getEtag($node);
        $this->assertSame(md5('42:100:2024-01-01 12:00:00'), $etag);
        $this->assertSame($etag, $this->backend->getEtag($node));
    }

    public function testNormalizePrefix()
    {
        $this->assertSame('', $this->backend->exposeNormalizePrefix(''));
        $this->assertSame('folder/', $this->backend->exposeNormalizePrefix('folder'));
        $this->assertSame('folder/', $this->backend->exposeNormalizePrefix('folder/'));
        $this->assertSame('folder/file.txt', $this->backend->exposeNormalizePrefix('folder/file.txt'));
        $this->assertSame('dir/', $this->backend->exposeNormalizePrefix('/dir'));
    }

    public function testPutObjectCreatesMissingParentFoldersForNestedKey()
    {
        $storage = array(
            'id'              => 1,
            'name'            => 'docs',
            'type'            => 'folder',
            'is_storage'      => true,
            'create_datetime' => '2024-01-01 00:00:00',
        );
        // Simulate resolveKey for brand-new nested key: parents missing, last_folder_exists=false.
        $this->backend->seedResolveState(
            1,
            $storage,
            false,
            $storage,
            'ON_DOC.xml/1/meta.xml'
        );

        $stream = fopen('php://temp', 'r');
        $this->assertTrue($this->backend->putObject($stream, 10));
        fclose($stream);

        $this->assertCount(1, $this->backend->ensure_folders_calls);
        $this->assertSame(array(1, 'ON_DOC.xml/1'), $this->backend->ensure_folders_calls[0]);
        $this->assertSame('meta.xml', $this->backend->last_create_file['name']);
        $this->assertSame(42, $this->backend->last_create_file['parent_id']);
    }

    public function testPutObjectStillReplacesExistingFile()
    {
        $storage = array(
            'id'         => 1,
            'name'       => 'docs',
            'type'       => 'folder',
            'is_storage' => true,
        );
        $file = array(
            'id'         => 9,
            'name'       => 'meta.xml',
            'type'       => 'file',
            'storage_id' => 1,
            'parent_id'  => 3,
            'size'       => 1,
        );
        $this->backend->seedResolveState(1, $storage, true, $file, 'folder/meta.xml');

        $stream = fopen('php://temp', 'r');
        $this->assertTrue($this->backend->putObject($stream, 5));
        fclose($stream);

        $this->assertEmpty($this->backend->ensure_folders_calls);
        $this->assertNotNull($this->backend->last_replace_file);
        $this->assertSame(9, $this->backend->last_replace_file['id']);
        $this->assertSame(5, $this->backend->last_replace_file['size']);
    }
}

/**
 * Calls real initRootUrl() for settlement-path root_url assertions.
 */
class filesS3BackendSettlementRootUrlProbe extends filesS3Backend
{
    public function exposeRootUrl()
    {
        return $this->root_url;
    }
}
