<?php

class FilesS3SignatureV4Test extends FilesS3TestCase
{
    const ACCESS_KEY = 's3user';
    const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
    const REGION = 'us-east-1';
    const HOST = 'example.com';

    /**
     * @return FilesS3SignatureV4TestDouble
     */
    protected function newSig()
    {
        return new FilesS3SignatureV4TestDouble(self::REGION);
    }

    public function testGetAccessKeyFromAuthorizationV4()
    {
        FilesS3RequestHelper::apply(array(
            'method'  => 'GET',
            'uri'     => '/bucket/key',
            'host'    => self::HOST,
            'headers' => array(
                'Authorization' => 'AWS4-HMAC-SHA256 Credential=my-access/20240101/us-east-1/s3/aws4_request, '
                    . 'SignedHeaders=host, Signature=abc',
            ),
        ));

        $this->assertSame('my-access', $this->newSig()->getAccessKey());
    }

    public function testGetAccessKeyFromAuthorizationV2()
    {
        FilesS3RequestHelper::apply(array(
            'method'  => 'GET',
            'uri'     => '/bucket/key',
            'host'    => self::HOST,
            'headers' => array(
                'Authorization' => 'AWS v2user:base64sig==',
            ),
        ));

        $this->assertSame('v2user', $this->newSig()->getAccessKey());
    }

    public function testGetAccessKeyFromPresignedQuery()
    {
        FilesS3RequestHelper::apply(array(
            'method' => 'GET',
            'uri'    => '/bucket/key',
            'host'   => self::HOST,
            'query'  => array(
                'X-Amz-Credential' => 'presign-key/20240101/us-east-1/s3/aws4_request',
                'X-Amz-Signature'  => 'deadbeef',
            ),
        ));

        $this->assertSame('presign-key', $this->newSig()->getAccessKey());
    }

    public function testVerifySigV4Get()
    {
        $uri = '/files/docs/hello.txt';
        $signed = FilesS3SigV4RequestBuilder::sign(array(
            'access_key' => self::ACCESS_KEY,
            'secret_key' => self::SECRET_KEY,
            'region'     => self::REGION,
            'method'     => 'GET',
            'uri'        => $uri,
            'host'       => self::HOST,
        ));

        FilesS3RequestHelper::apply(array(
            'method'  => 'GET',
            'uri'     => $uri,
            'host'    => self::HOST,
            'headers' => $signed['headers'],
        ));

        $sig = $this->newSig();
        $this->assertTrue($sig->verify(self::SECRET_KEY));
        $this->assertFalse($sig->verify('wrong-secret'));
    }

    public function testVerifySigV4HeadBucket()
    {
        $uri = '/bucket-name';
        $signed = FilesS3SigV4RequestBuilder::sign(array(
            'access_key' => self::ACCESS_KEY,
            'secret_key' => self::SECRET_KEY,
            'region'     => self::REGION,
            'method'     => 'HEAD',
            'uri'        => $uri,
            'host'       => self::HOST,
        ));

        FilesS3RequestHelper::apply(array(
            'method'  => 'HEAD',
            'uri'     => $uri,
            'host'    => self::HOST,
            'headers' => $signed['headers'],
        ));

        $this->assertTrue($this->newSig()->verify(self::SECRET_KEY));
        $this->assertFalse($this->newSig()->verify('wrong-secret'));
    }

    public function testVerifySigV4HeadBucketWithContentLengthZero()
    {
        $uri = '/bucket';
        $signed = FilesS3SigV4RequestBuilder::sign(array(
            'access_key' => self::ACCESS_KEY,
            'secret_key' => self::SECRET_KEY,
            'region'     => self::REGION,
            'method'     => 'HEAD',
            'uri'        => $uri,
            'host'       => self::HOST,
            'headers'    => array(
                'content-length' => '0',
            ),
        ));

        FilesS3RequestHelper::apply(array(
            'method'  => 'HEAD',
            'uri'     => $uri,
            'host'    => self::HOST,
            'headers' => $signed['headers'],
        ));
        $this->assertSame('0', $_SERVER['CONTENT_LENGTH']);

        $this->assertTrue($this->newSig()->verify(self::SECRET_KEY));
    }

    public function testVerifySigV4HeadBucketWhenContentLengthStripped()
    {
        $uri = '/bucket';
        $signed = FilesS3SigV4RequestBuilder::sign(array(
            'access_key' => self::ACCESS_KEY,
            'secret_key' => self::SECRET_KEY,
            'region'     => self::REGION,
            'method'     => 'HEAD',
            'uri'        => $uri,
            'host'       => self::HOST,
            'headers'    => array(
                'content-length' => '0',
            ),
        ));

        FilesS3RequestHelper::apply(array(
            'method'  => 'HEAD',
            'uri'     => $uri,
            'host'    => self::HOST,
            'headers' => $signed['headers'],
        ));
        // Proxies / PHP often omit Content-Length for HEAD even if the client signed "0".
        unset($_SERVER['CONTENT_LENGTH']);

        $this->assertTrue($this->newSig()->verify(self::SECRET_KEY));
    }

    public function testVerifySigV4HeadBucketWhenAcceptEncodingStripped()
    {
        $uri = '/bucket';
        $signed = FilesS3SigV4RequestBuilder::sign(array(
            'access_key' => self::ACCESS_KEY,
            'secret_key' => self::SECRET_KEY,
            'region'     => self::REGION,
            'method'     => 'HEAD',
            'uri'        => $uri,
            'host'       => self::HOST,
            'headers'    => array(
                'accept-encoding' => 'identity',
            ),
        ));

        FilesS3RequestHelper::apply(array(
            'method'  => 'HEAD',
            'uri'     => $uri,
            'host'    => self::HOST,
            'headers' => $signed['headers'],
        ));
        unset($_SERVER['HTTP_ACCEPT_ENCODING']);

        $this->assertTrue($this->newSig()->verify(self::SECRET_KEY));
    }

    public function testVerifySigV4PutWithPayloadHash()
    {
        $uri = '/files/docs/upload.bin';
        $payload = 'payload-bytes';
        $signed = FilesS3SigV4RequestBuilder::sign(array(
            'access_key' => self::ACCESS_KEY,
            'secret_key' => self::SECRET_KEY,
            'region'     => self::REGION,
            'method'     => 'PUT',
            'uri'        => $uri,
            'host'       => self::HOST,
            'payload'    => $payload,
        ));

        FilesS3RequestHelper::apply(array(
            'method'  => 'PUT',
            'uri'     => $uri,
            'host'    => self::HOST,
            'headers' => $signed['headers'],
        ));

        $this->assertTrue($this->newSig()->verify(self::SECRET_KEY));
    }

    public function testVerifySigV4EmptyBodyCopyObject()
    {
        $uri = '/files/docs/dest.txt';
        $signed = FilesS3SigV4RequestBuilder::sign(array(
            'access_key' => self::ACCESS_KEY,
            'secret_key' => self::SECRET_KEY,
            'region'     => self::REGION,
            'method'     => 'PUT',
            'uri'        => $uri,
            'host'       => self::HOST,
            'payload'    => '',
            'headers'    => array(
                'x-amz-copy-source'        => '/docs/src.txt',
                'x-amz-metadata-directive' => 'COPY',
            ),
        ));

        FilesS3RequestHelper::apply(array(
            'method'  => 'PUT',
            'uri'     => $uri,
            'host'    => self::HOST,
            'headers' => $signed['headers'],
        ));

        $this->assertTrue($this->newSig()->verify(self::SECRET_KEY));
    }

    public function testVerifySigV4CopySourceEncoded()
    {
        $uri = '/files/docs/dest.txt';
        $copy_source = '/docs/' . rawurlencode('my file.txt');
        $signed = FilesS3SigV4RequestBuilder::sign(array(
            'access_key' => self::ACCESS_KEY,
            'secret_key' => self::SECRET_KEY,
            'region'     => self::REGION,
            'method'     => 'PUT',
            'uri'        => $uri,
            'host'       => self::HOST,
            'payload'    => '',
            'headers'    => array(
                'x-amz-copy-source' => $copy_source,
            ),
        ));

        FilesS3RequestHelper::apply(array(
            'method'  => 'PUT',
            'uri'     => $uri,
            'host'    => self::HOST,
            'headers' => $signed['headers'],
        ));

        $this->assertTrue($this->newSig()->verify(self::SECRET_KEY));
    }

    public function testVerifySigV2()
    {
        $uri = '/files/docs/a.txt';
        $signed = FilesS3SigV4RequestBuilder::signV2(array(
            'access_key' => self::ACCESS_KEY,
            'secret_key' => self::SECRET_KEY,
            'method'     => 'GET',
            'uri'        => $uri,
            'host'       => self::HOST,
        ));

        FilesS3RequestHelper::apply(array(
            'method'  => 'GET',
            'uri'     => $uri,
            'host'    => self::HOST,
            'headers' => $signed['headers'],
        ));

        $sig = $this->newSig();
        $this->assertTrue($sig->verify(self::SECRET_KEY));
        $this->assertFalse($sig->verify('bad'));
    }

    public function testVerifyPresigned()
    {
        $amz_date = gmdate('Ymd\THis\Z');
        $date = substr($amz_date, 0, 8);
        $credential = self::ACCESS_KEY . '/' . $date . '/' . self::REGION . '/s3/aws4_request';
        $uri_path = '/files/docs/a.txt';
        $query = array(
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => $credential,
            'X-Amz-Date'          => $amz_date,
            'X-Amz-Expires'       => '3600',
            'X-Amz-SignedHeaders' => 'host',
        );
        ksort($query);
        $canonical_query = FilesS3SigV4RequestBuilder::encodeQuery($query);
        $canonical_uri = FilesS3SigV4RequestBuilder::encodePath($uri_path);
        $payload_hash = 'UNSIGNED-PAYLOAD';
        $canonical_headers = "host:" . self::HOST . "\n";
        $signed_headers = 'host';
        $canonical_request = "GET\n{$canonical_uri}\n{$canonical_query}\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";
        $credential_scope = $date . '/' . self::REGION . '/s3/aws4_request';
        $string_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$credential_scope}\n" . hash('sha256', $canonical_request);
        $signing_key = FilesS3SigV4RequestBuilder::signingKey(self::SECRET_KEY, $date, self::REGION);
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
        $query['X-Amz-Signature'] = $signature;

        // Use AWS encoding for the request URI query (not PHP http_build_query).
        $uri = $uri_path . '?' . FilesS3SigV4RequestBuilder::encodeQuery($query);

        FilesS3RequestHelper::apply(array(
            'method'  => 'GET',
            'uri'     => $uri,
            'host'    => self::HOST,
            'headers' => array(
                'host' => self::HOST,
            ),
        ));
        // Ensure $_GET matches encoded query parsing used by the verifier.
        $_GET = $query;

        $this->assertTrue($this->newSig()->verify(self::SECRET_KEY));
    }

    public function testVerifyFailsWithoutAuthorization()
    {
        FilesS3RequestHelper::apply(array(
            'method' => 'GET',
            'uri'    => '/files/docs/a.txt',
            'host'   => self::HOST,
        ));

        $sig = $this->newSig();
        $this->assertNull($sig->getAccessKey());
        $this->assertFalse($sig->verify(self::SECRET_KEY));
    }

    public function testVerifyFailsMissingAmzDate()
    {
        $uri = '/files/docs/a.txt';
        $signed = FilesS3SigV4RequestBuilder::sign(array(
            'access_key' => self::ACCESS_KEY,
            'secret_key' => self::SECRET_KEY,
            'region'     => self::REGION,
            'method'     => 'GET',
            'uri'        => $uri,
            'host'       => self::HOST,
        ));
        unset($signed['headers']['x-amz-date']);

        FilesS3RequestHelper::apply(array(
            'method'  => 'GET',
            'uri'     => $uri,
            'host'    => self::HOST,
            'headers' => $signed['headers'],
        ));
        unset($_SERVER['HTTP_X_AMZ_DATE']);

        $this->assertFalse($this->newSig()->verify(self::SECRET_KEY));
    }

    public function testGetRequestHeader()
    {
        FilesS3RequestHelper::apply(array(
            'method'  => 'PUT',
            'uri'     => '/x',
            'host'    => self::HOST,
            'headers' => array(
                'x-amz-copy-source' => '/bucket/key',
            ),
        ));

        $sig = $this->newSig();
        $this->assertSame('/bucket/key', $sig->getRequestHeader('x-amz-copy-source'));
        $this->assertSame('', $sig->getRequestHeader('x-amz-missing'));
    }
}
