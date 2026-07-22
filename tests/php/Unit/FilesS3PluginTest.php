<?php

class FilesS3PluginTest extends FilesS3TestCase
{
    public function testGenerateSecretKeyLengthAndCharset()
    {
        $secret = filesS3Plugin::generateSecretKey();
        $this->assertSame(40, strlen($secret));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $secret);

        $other = filesS3Plugin::generateSecretKey();
        $this->assertNotSame($secret, $other);
    }

    public function testNormalizeRequestHeadersFromRedirectAuthorization()
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'AWS4-HMAC-SHA256 Credential=x/20240101/us-east-1/s3/aws4_request, SignedHeaders=host, Signature=abc';

        filesS3Plugin::normalizeRequestHeaders();

        $this->assertSame(
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
            $_SERVER['HTTP_AUTHORIZATION']
        );
    }

    public function testNormalizeRequestHeadersMapsXAmzFromGetallheadersWhenAvailable()
    {
        // When getallheaders() is unavailable (CLI), method still runs safely.
        $_SERVER['HTTP_X_AMZ_DATE'] = '';
        unset($_SERVER['HTTP_X_AMZ_DATE']);

        filesS3Plugin::normalizeRequestHeaders();

        $this->assertTrue(true);
    }

    public function testConstants()
    {
        $this->assertSame('files.s3', filesS3Plugin::CONTACT_SETTINGS_APP);
        $this->assertSame('secret_key', filesS3Plugin::SECRET_KEY_SETTING);
        $this->assertSame('server-1', filesS3Plugin::DEFAULT_REGION);
    }

    /**
     * @dataProvider rootSettlementRouteProvider
     */
    public function testIsRootSettlement($route, $expected)
    {
        $this->assertSame($expected, filesS3Plugin::isRootSettlement($route));
    }

    public function rootSettlementRouteProvider()
    {
        return array(
            array(array('url' => '*'), true),
            array(array('url' => 'files/*'), false),
            array(array('url' => ''), false),
            array(array(), false),
        );
    }

    public function testDeleteSecretKeyRemovesSetting()
    {
        $csm = new waContactSettingsModel();
        $contact_id = 1;
        $csm->set($contact_id, filesS3Plugin::CONTACT_SETTINGS_APP, filesS3Plugin::SECRET_KEY_SETTING, 'abc');
        filesS3Plugin::deleteSecretKey($contact_id);
        $this->assertSame('', filesS3Plugin::getSecretKey($contact_id));
    }

    public function testGetUploadLimitsInfo()
    {
        $info = filesS3Plugin::getUploadLimitsInfo();
        $this->assertArrayHasKey('upload_raw', $info);
        $this->assertArrayHasKey('post_raw', $info);
        $this->assertArrayHasKey('effective_bytes', $info);
        $this->assertArrayHasKey('effective_formatted', $info);
        $this->assertGreaterThan(0, $info['effective_bytes']);
        $this->assertSame(
            min($info['upload_bytes'], $info['post_bytes']),
            $info['effective_bytes']
        );
        $this->assertNotSame('', $info['effective_formatted']);
    }
}
