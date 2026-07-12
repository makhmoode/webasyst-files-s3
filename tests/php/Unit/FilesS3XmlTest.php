<?php

class FilesS3XmlTest extends FilesS3TestCase
{
    public function testErrorEscapesXmlAndIncludesRequestId()
    {
        $xml = filesS3Xml::error('AccessDenied', 'A <b> & "c"', '/bucket/key', 'req123');
        $doc = $this->parseXml($xml);

        $this->assertSame('AccessDenied', (string) $doc->Code);
        $this->assertSame('A <b> & "c"', (string) $doc->Message);
        $this->assertSame('/bucket/key', (string) $doc->Resource);
        $this->assertSame('req123', (string) $doc->RequestId);
        $this->assertStringContainsString('&lt;b&gt;', $xml);
        $this->assertStringContainsString('&amp;', $xml);
    }

    public function testListBuckets()
    {
        $xml = filesS3Xml::listBuckets(array(
            array('name' => 'alpha', 'create_datetime' => '2024-01-15 10:00:00'),
            array('name' => 'beta & gamma', 'create_datetime' => '2024-02-01 12:30:00'),
        ));
        $doc = $this->parseXml($xml);

        $this->assertSame('ListAllMyBucketsResult', $doc->getName());
        $this->assertCount(2, $doc->Buckets->Bucket);
        $this->assertSame('alpha', (string) $doc->Buckets->Bucket[0]->Name);
        $this->assertSame('beta & gamma', (string) $doc->Buckets->Bucket[1]->Name);
        $this->assertStringContainsString('2024-01-15T10:00:00.000Z', $xml);
    }

    public function testListObjectsV2KeyCountIncludesCommonPrefixes()
    {
        $items = array(
            array(
                'key'           => 'folder/file.txt',
                'size'          => 10,
                'etag'          => 'abc',
                'last_modified' => '2024-03-01 08:00:00',
            ),
        );
        $prefixes = array('folder/sub/');

        $xml = filesS3Xml::listObjectsV2(
            'bucket',
            'folder/',
            '/',
            1000,
            '',
            $items,
            $prefixes,
            false,
            ''
        );
        $doc = $this->parseXml($xml);

        $this->assertSame('2', (string) $doc->KeyCount);
        $this->assertSame('folder/', (string) $doc->Prefix);
        $this->assertSame('/', (string) $doc->Delimiter);
        $this->assertSame('false', (string) $doc->IsTruncated);
        $this->assertSame('folder/sub/', (string) $doc->CommonPrefixes->Prefix);
        $this->assertSame('folder/file.txt', (string) $doc->Contents->Key);
        $this->assertSame('10', (string) $doc->Contents->Size);
        $this->assertSame('"abc"', (string) $doc->Contents->ETag);
    }

    public function testListObjectsV2EmptyFolderMarkerInContents()
    {
        $items = array(
            array(
                'key'           => 'empty-folder/',
                'size'          => 0,
                'etag'          => 'folder-etag',
                'last_modified' => '2024-04-01 00:00:00',
            ),
        );

        $xml = filesS3Xml::listObjectsV2(
            'bucket',
            'empty-folder/',
            '/',
            1000,
            '',
            $items,
            array(),
            false,
            ''
        );
        $doc = $this->parseXml($xml);

        $this->assertSame('1', (string) $doc->KeyCount);
        $this->assertCount(1, $doc->Contents);
        $this->assertSame('empty-folder/', (string) $doc->Contents->Key);
        $this->assertSame('0', (string) $doc->Contents->Size);
        $this->assertSame('false', (string) $doc->IsTruncated);
    }

    public function testListObjectsV2TruncationToken()
    {
        $xml = filesS3Xml::listObjectsV2(
            'b',
            '',
            '',
            1,
            'token-in',
            array(
                array(
                    'key'           => 'a.txt',
                    'size'          => 1,
                    'etag'          => 'e',
                    'last_modified' => '2024-01-01 00:00:00',
                ),
            ),
            array(),
            true,
            'token-next'
        );
        $doc = $this->parseXml($xml);

        $this->assertSame('true', (string) $doc->IsTruncated);
        $this->assertSame('token-in', (string) $doc->ContinuationToken);
        $this->assertSame('token-next', (string) $doc->NextContinuationToken);
    }

    public function testListObjectsV1()
    {
        $xml = filesS3Xml::listObjectsV1(
            'bucket',
            'p/',
            '/',
            10,
            'marker',
            array(
                array(
                    'key'           => 'p/f.txt',
                    'size'          => 3,
                    'etag'          => 'x',
                    'last_modified' => '2024-05-01 01:02:03',
                ),
            ),
            array('p/d/'),
            true,
            'next-marker'
        );
        $doc = $this->parseXml($xml);

        $this->assertSame('marker', (string) $doc->Marker);
        $this->assertSame('next-marker', (string) $doc->NextMarker);
        $this->assertSame('p/d/', (string) $doc->CommonPrefixes->Prefix);
        $this->assertSame('p/f.txt', (string) $doc->Contents->Key);
    }

    public function testDeleteObjects()
    {
        $xml = filesS3Xml::deleteObjects(
            array('a.txt', 'b.txt'),
            array(
                array('key' => 'c.txt', 'code' => 'AccessDenied', 'message' => 'nope'),
            )
        );
        $doc = $this->parseXml($xml);

        $this->assertCount(2, $doc->Deleted);
        $this->assertSame('a.txt', (string) $doc->Deleted[0]->Key);
        $this->assertSame('c.txt', (string) $doc->Error->Key);
        $this->assertSame('AccessDenied', (string) $doc->Error->Code);
    }

    public function testCopyObjectResult()
    {
        $xml = filesS3Xml::copyObjectResult('etag123', '2024-06-01T12:00:00.000Z');
        $doc = $this->parseXml($xml);

        $this->assertSame('CopyObjectResult', $doc->getName());
        $this->assertSame('"etag123"', (string) $doc->ETag);
        $this->assertSame('2024-06-01T12:00:00.000Z', (string) $doc->LastModified);
    }

    public function testMultipartXmlBuilders()
    {
        $init = $this->parseXml(filesS3Xml::initiateMultipartUpload('b', 'k', 'uid-1'));
        $this->assertSame('uid-1', (string) $init->UploadId);

        $complete = $this->parseXml(filesS3Xml::completeMultipartUpload('b', 'k', 'etag-m'));
        $this->assertSame('"etag-m"', (string) $complete->ETag);
        $this->assertSame('/b/k', (string) $complete->Location);

        $parts = $this->parseXml(filesS3Xml::listParts('b', 'k', 'uid-1', array(
            array(
                'part_number'   => 1,
                'etag'          => 'p1',
                'size'          => 100,
                'last_modified' => '2024-07-01 00:00:00',
            ),
        )));
        $this->assertSame('1', (string) $parts->Part->PartNumber);
        $this->assertSame('100', (string) $parts->Part->Size);
    }
}
