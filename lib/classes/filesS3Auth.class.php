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
     * @return bool
     */
    public function authenticate()
    {
        if (wa()->getUser()->isAuth()) {
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

        wa()->getAuth()->auth(array('id' => $contact['id']));
        wa()->setLocale(wa()->getUser()->getLocale());
        return true;
    }
}
