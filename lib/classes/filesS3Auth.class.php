<?php

class filesS3Auth
{
    /**
     * @var string
     */
    protected $region;

    public function __construct($region = 'us-east-1')
    {
        $this->region = $region ?: 'us-east-1';
    }

    /**
     * Authenticate S3 request via AWS Signature V4 or presigned URL.
     *
     * Uses sessionless user binding so multipart UploadPart requests keep a stable
     * contact id without PHP session locks / headers-already-sent failures.
     *
     * @return bool
     */
    public function authenticate()
    {
        if (wa()->getUser()->isAuth()) {
            self::bindFilesAppUser(wa()->getUser());
            return true;
        }

        $sig = new filesS3SignatureV4($this->region);
        $access_key = $sig->getAccessKey();
        if (!$access_key) {
            return false;
        }

        $cm = new waContactModel();
        $contact = $cm->getByField('login', $access_key);
        if (!$contact) {
            return false;
        }

        $secret = filesS3Plugin::getSecretKey($contact['id']);
        if ($secret === '') {
            return false;
        }

        if (!$sig->verify($secret)) {
            return false;
        }

        $user = new filesS3AuthUser($contact['id']);
        if (!$user->isAuth()) {
            return false;
        }

        self::bindFilesAppUser($user);
        return true;
    }

    /**
     * Bind authenticated user for S3 and reset Files rights singleton.
     *
     * filesRights caches the first request user (often a guest from frontend
     * bootstrap). Without resetting it, limited/personal storages stay invisible
     * after sessionless setUser(). Kept inside the plugin to avoid Files app edits.
     *
     * @param waUser|waContact $user
     */
    public static function bindFilesAppUser($user)
    {
        wa()->setUser($user);
        if (method_exists($user, 'getLocale')) {
            wa()->setLocale($user->getLocale());
        }
        self::resetFilesRightsSingleton();
    }

    /**
     * Drop filesRights request singleton so the next inst() picks up wa()->getUser().
     */
    protected static function resetFilesRightsSingleton()
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
        if ($prop->isStatic()) {
            $prop->setValue(null, null);
        }
    }
}
