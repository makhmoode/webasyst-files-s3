<?php

class FilesS3ChunkedDecoderTest extends FilesS3TestCase
{
    public function testIsAwsChunkedByContentEncoding()
    {
        FilesS3RequestHelper::apply(array(
            'method'  => 'PUT',
            'uri'     => '/files/docs/a.bin',
            'headers' => array(
                'Content-Encoding' => 'aws-chunked',
            ),
        ));
        $_SERVER['HTTP_CONTENT_ENCODING'] = 'aws-chunked';
        $this->assertTrue(filesS3ChunkedDecoder::isAwsChunkedRequest());
    }

    public function testIsAwsChunkedByDecodedContentLength()
    {
        FilesS3RequestHelper::apply(array(
            'method'  => 'PUT',
            'uri'     => '/files/docs/a.bin',
            'headers' => array(
                'x-amz-decoded-content-length' => '10',
            ),
        ));
        $_SERVER['HTTP_X_AMZ_DECODED_CONTENT_LENGTH'] = '10';
        $this->assertTrue(filesS3ChunkedDecoder::isAwsChunkedRequest());
    }

    public function testIsAwsChunkedByStreamingPayloadHash()
    {
        FilesS3RequestHelper::apply(array(
            'method'  => 'PUT',
            'uri'     => '/files/docs/a.bin',
            'headers' => array(
                'x-amz-content-sha256' => 'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
            ),
        ));
        $_SERVER['HTTP_X_AMZ_CONTENT_SHA256'] = 'STREAMING-UNSIGNED-PAYLOAD-TRAILER';
        $this->assertTrue(filesS3ChunkedDecoder::isAwsChunkedRequest());
    }

    public function testIsNotAwsChunkedForPlainPut()
    {
        FilesS3RequestHelper::apply(array(
            'method'  => 'PUT',
            'uri'     => '/files/docs/a.bin',
            'headers' => array(
                'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD',
                'Content-Length'       => '4',
            ),
        ));
        $_SERVER['HTTP_X_AMZ_CONTENT_SHA256'] = 'UNSIGNED-PAYLOAD';
        unset($_SERVER['HTTP_CONTENT_ENCODING'], $_SERVER['HTTP_X_AMZ_DECODED_CONTENT_LENGTH']);
        $this->assertFalse(filesS3ChunkedDecoder::isAwsChunkedRequest());
    }

    public function testDecodeSingleChunkWithChecksumTrailer()
    {
        $payload = 'hello-aws-chunked';
        $body = dechex(strlen($payload)) . "\r\n"
            . $payload . "\r\n"
            . "0\r\n"
            . "x-amz-checksum-crc64nvme:D28G+z12mI=\r\n"
            . "\r\n";

        $result = $this->decodeString($body);
        $this->assertNotFalse($result);
        list($stream, $length) = $result;
        $decoded = stream_get_contents($stream);
        $this->assertSame(strlen($payload), $length);
        $this->assertSame($payload, $decoded);
        $this->assertStringNotContainsString('x-amz-checksum', $decoded);
        $this->assertStringNotContainsString("\r\n0\r\n", $decoded);
        fclose($stream);
    }

    public function testDecodeMultipleChunks()
    {
        $part1 = 'AAAA';
        $part2 = 'BBBBBB';
        $body = dechex(strlen($part1)) . "\r\n" . $part1 . "\r\n"
            . dechex(strlen($part2)) . "\r\n" . $part2 . "\r\n"
            . "0\r\n"
            . "x-amz-checksum-crc32:AAAAAA==\r\n"
            . "\r\n";

        $result = $this->decodeString($body);
        $this->assertNotFalse($result);
        list($stream, $length) = $result;
        $this->assertSame(strlen($part1 . $part2), $length);
        $this->assertSame($part1 . $part2, stream_get_contents($stream));
        fclose($stream);
    }

    public function testDecodeChunkWithSignatureSuffix()
    {
        $payload = 'signed-chunk-data';
        $body = dechex(strlen($payload)) . ';chunk-signature=abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789' . "\r\n"
            . $payload . "\r\n"
            . "0;chunk-signature=ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff\r\n"
            . "\r\n";

        $result = $this->decodeString($body);
        $this->assertNotFalse($result);
        list($stream, $length) = $result;
        $this->assertSame(strlen($payload), $length);
        $this->assertSame($payload, stream_get_contents($stream));
        fclose($stream);
    }

    public function testDecodeRejectsMalformedSizeLine()
    {
        $body = "not-hex\r\ndata\r\n0\r\n\r\n";
        $this->assertFalse($this->decodeString($body));
    }

    public function testDecodeEmptyObject()
    {
        $body = "0\r\n\r\n";
        $result = $this->decodeString($body);
        $this->assertNotFalse($result);
        list($stream, $length) = $result;
        $this->assertSame(0, $length);
        $this->assertSame('', stream_get_contents($stream));
        fclose($stream);
    }

    /**
     * @param string $body
     * @return array|false
     */
    protected function decodeString($body)
    {
        $input = fopen('php://temp', 'w+b');
        fwrite($input, $body);
        rewind($input);
        $result = filesS3ChunkedDecoder::decode($input);
        fclose($input);
        return $result;
    }
}
