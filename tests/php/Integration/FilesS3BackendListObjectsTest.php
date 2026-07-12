<?php

/**
 * @group long_tests
 */
class FilesS3BackendListObjectsTest extends FilesS3IntegrationTestCase
{
    public function testEmptyFolderReturnsFolderMarkerInContents()
    {
        $folder = $this->addFolder('empty_dir_' . uniqid());
        $bucket = self::$storage['name'];
        $prefix = $folder['name'] . '/';

        $backend = $this->createBackend();
        $result = $backend->listObjects($bucket, $prefix, '/', 1000, '');

        $this->assertIsArray($result);
        $this->assertSame(array(), $result['common_prefixes']);
        $this->assertCount(1, $result['items']);
        $this->assertSame($prefix, $result['items'][0]['key']);
        $this->assertSame(0, $result['items'][0]['size']);
        $this->assertFalse($result['is_truncated']);
    }

    public function testNonEmptyFolderListsFilesAndCommonPrefixes()
    {
        $folder = $this->addFolder('mixed_' . uniqid());
        $this->addFileNode('readme.txt', 'content', $folder['id']);
        $this->addFolder('child', $folder['id']);

        $bucket = self::$storage['name'];
        $prefix = $folder['name'] . '/';

        $backend = $this->createBackend();
        $result = $backend->listObjects($bucket, $prefix, '/', 1000, '');

        $this->assertIsArray($result);
        $this->assertContains($prefix . 'child/', $result['common_prefixes']);

        $keys = array_column($result['items'], 'key');
        $this->assertContains($prefix, $keys);
        $this->assertContains($prefix . 'readme.txt', $keys);
    }

    public function testMissingPrefixReturnsEmptyResult()
    {
        $bucket = self::$storage['name'];
        $backend = $this->createBackend();
        $result = $backend->listObjects($bucket, 'no_such_folder_' . uniqid() . '/', '/', 1000, '');

        $this->assertIsArray($result);
        $this->assertSame(array(), $result['items']);
        $this->assertSame(array(), $result['common_prefixes']);
        $this->assertFalse($result['is_truncated']);
    }

    public function testRootListingIncludesFoldersAsCommonPrefixes()
    {
        $folder = $this->addFolder('root_folder_' . uniqid());
        $bucket = self::$storage['name'];

        $backend = $this->createBackend();
        $result = $backend->listObjects($bucket, '', '/', 1000, '');

        $this->assertIsArray($result);
        $this->assertContains($folder['name'] . '/', $result['common_prefixes']);
    }
}
