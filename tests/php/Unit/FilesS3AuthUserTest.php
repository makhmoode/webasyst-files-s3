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
