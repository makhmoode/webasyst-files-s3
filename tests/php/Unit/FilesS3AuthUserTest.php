<?php

class FilesS3AuthUserTest extends FilesS3TestCase
{
    public function testIsAuthRequiresPositiveId()
    {
        $user = new filesS3AuthUserTestDouble(0);
        $this->assertFalse($user->isAuth());
        $this->assertSame(0, $user->getId());
    }

    public function testIsAuthTrueForPositiveId()
    {
        $user = new filesS3AuthUserTestDouble(42);
        $this->assertTrue($user->isAuth());
        $this->assertSame(42, $user->getId());
    }

    public function testSetUserMakesWaUserAuthWithoutSession()
    {
        if (empty($GLOBALS['files_s3_files_app_ready'])) {
            $this->markTestSkipped('files app not available');
        }

        $previous = wa()->getUser();
        try {
            $user = new filesS3AuthUserTestDouble(7);
            filesS3Auth::bindFilesAppUser($user);
            $this->assertTrue(wa()->getUser()->isAuth());
            $this->assertSame(7, (int) wa()->getUser()->getId());
        } finally {
            wa()->setUser($previous);
            self::resetFilesRightsForTest();
        }
    }

    public function testBindFilesAppUserResetsRightsSingleton()
    {
        if (empty($GLOBALS['files_s3_files_app_ready'])) {
            $this->markTestSkipped('files app not available');
        }

        $previous = wa()->getUser();
        try {
            $guest = new filesS3AuthUserTestDouble(0);
            wa()->setUser($guest);
            $guest_rights = filesRights::inst();
            $this->assertSame(0, (int) $guest_rights->getUser()->getId());

            $user = new filesS3AuthUserTestDouble(15);
            filesS3Auth::bindFilesAppUser($user);

            $rights = filesRights::inst();
            $this->assertNotSame($guest_rights, $rights);
            $this->assertSame(15, (int) $rights->getUser()->getId());

            $ref = new ReflectionClass($rights);
            $contact_id = $ref->getProperty('contact_id');
            $contact_id->setAccessible(true);
            $this->assertSame(15, (int) $contact_id->getValue($rights));
        } finally {
            wa()->setUser($previous);
            self::resetFilesRightsForTest();
        }
    }

    public function testWebDavProtocolRequestIsDetectedSeparatelyFromS3()
    {
        FilesS3RequestHelper::apply(array(
            'method' => 'GET',
            'uri'    => '/bucket',
            'host'   => 'example.com',
        ));
        $this->assertFalse(filesS3Auth::isS3ProtocolRequest());
        $this->assertFalse(filesS3Auth::isWebDavProtocolRequest());

        FilesS3RequestHelper::apply(array(
            'method'  => 'PROPFIND',
            'uri'     => '/bucket',
            'host'    => 'example.com',
            'headers' => array(
                'Depth' => '1',
            ),
        ));
        $this->assertTrue(filesS3Auth::isWebDavProtocolRequest());

        FilesS3RequestHelper::apply(array(
            'method'  => 'GET',
            'uri'     => '/bucket',
            'host'    => 'example.com',
            'headers' => array(
                'Depth' => '0',
            ),
        ));
        $this->assertTrue(filesS3Auth::isWebDavProtocolRequest());
    }

    public function testHasRequestSignatureDetectsAuthorizationAndPresign()
    {
        FilesS3RequestHelper::apply(array(
            'method' => 'GET',
            'uri'    => '/bucket',
            'host'   => 'example.com',
        ));
        $this->assertFalse(filesS3Auth::hasRequestSignature());
        $this->assertFalse(filesS3Auth::isS3ProtocolRequest());

        FilesS3RequestHelper::apply(array(
            'method'  => 'HEAD',
            'uri'     => '/bucket-name',
            'host'    => 'example.com',
            'headers' => array(
                'Authorization' => 'AWS4-HMAC-SHA256 Credential=x/20240101/us-east-1/s3/aws4_request, '
                    . 'SignedHeaders=host, Signature=abc',
            ),
        ));
        $this->assertTrue(filesS3Auth::hasRequestSignature());
        $this->assertTrue(filesS3Auth::isS3ProtocolRequest());

        FilesS3RequestHelper::apply(array(
            'method' => 'GET',
            'uri'    => '/bucket',
            'host'   => 'example.com',
            'query'  => array(
                'X-Amz-Signature' => 'deadbeef',
            ),
        ));
        $this->assertTrue(filesS3Auth::hasRequestSignature());
    }

    public function testAccessKeySecretFromBasicAuthorization()
    {
        FilesS3RequestHelper::apply(array(
            'method'  => 'HEAD',
            'uri'     => '/bucket-03',
            'host'    => 's3.loc',
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode('s3user:super-secret-key'),
            ),
        ));
        filesS3Plugin::recoverAuthorizationHeader();

        $creds = filesS3Auth::getAccessKeySecretFromRequest();
        $this->assertNotNull($creds);
        $this->assertSame('s3user', $creds['access_key']);
        $this->assertSame('super-secret-key', $creds['secret']);
        $this->assertTrue(filesS3Auth::isS3ProtocolRequest());
    }

    public function testAccessKeySecretFromPhpAuthUser()
    {
        FilesS3RequestHelper::apply(array(
            'method' => 'HEAD',
            'uri'    => '/bucket-03',
            'host'   => 's3.loc',
        ));
        $_SERVER['PHP_AUTH_USER'] = 's3user';
        $_SERVER['PHP_AUTH_PW'] = 'super-secret-key';

        $creds = filesS3Auth::getAccessKeySecretFromRequest();
        $this->assertSame('s3user', $creds['access_key']);
        $this->assertSame('super-secret-key', $creds['secret']);
        $this->assertTrue(filesS3Auth::isS3ProtocolRequest());
    }

    public function testAuthenticateDoesNotShortCircuitSessionWhenSignaturePresent()
    {
        if (empty($GLOBALS['files_s3_files_app_ready'])) {
            $this->markTestSkipped('files app not available');
        }

        $previous = wa()->getUser();
        try {
            wa()->setUser(new filesS3AuthUserTestDouble(99));
            $this->assertTrue(wa()->getUser()->isAuth());

            $uri = '/bucket-name';
            $signed = FilesS3SigV4RequestBuilder::sign(array(
                'access_key' => 'no_such_login_' . uniqid(),
                'secret_key' => 'any-secret',
                'region'     => 'us-east-1',
                'method'     => 'HEAD',
                'uri'        => $uri,
                'host'       => 'example.com',
            ));

            FilesS3RequestHelper::apply(array(
                'method'  => 'HEAD',
                'uri'     => $uri,
                'host'    => 'example.com',
                'headers' => $signed['headers'],
            ));

            $auth = new filesS3Auth('us-east-1');
            $this->assertFalse($auth->authenticate());
            $this->assertSame(99, (int) wa()->getUser()->getId());
        } finally {
            wa()->setUser($previous);
            self::resetFilesRightsForTest();
        }
    }

    protected static function resetFilesRightsForTest()
    {
        if (!class_exists('filesRights')) {
            return;
        }
        $ref = new ReflectionClass('filesRights');
        if (!$ref->hasProperty('instance')) {
            return;
        }
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
}
