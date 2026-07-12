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
            array('settlement' => 'example.com/files/')
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
}
