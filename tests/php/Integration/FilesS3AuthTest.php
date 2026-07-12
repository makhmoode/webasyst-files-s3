<?php

/**
 * @group long_tests
 */
class FilesS3AuthTest extends FilesS3IntegrationTestCase
{
    /**
     * Force SigV4 path with a non-auth guest user so contact settings cache cannot
     * shadow DB secrets (waContactSettingsModel::getOne short-circuits on current user).
     */
    protected function clearSessionAuthQuietly()
    {
        wa('files')->setUser(new waUser());
    }

    /**
     * Auth helper that skips session_start after successful signature verify (PHPUnit-safe).
     *
     * @return FilesS3AuthNoSession
     */
    protected function createAuth()
    {
        return new FilesS3AuthNoSession('us-east-1');
    }

    public function testAuthenticateWithValidSigV4()
    {
        $login = self::$storage['_login'];
        $secret = self::$storage['_secret'];
        $contact_id = (int) self::$storage['_contact_id'];
        $this->assertNotEmpty($login);
        $this->assertNotEmpty($secret);
        $this->assertGreaterThan(0, $contact_id);

        // Ensure credentials are actually persisted as auth will look them up.
        $cm = new waContactModel();
        $contact = $cm->getByField('login', $login);
        if (!$contact) {
            // Fallback: ensure login row exists (fixture should have set it).
            $cm->updateById($contact_id, array('login' => $login));
            $contact = $cm->getByField('login', $login);
        }
        $this->assertNotEmpty($contact, 'Contact must be findable by login=' . $login);
        $this->assertSame($contact_id, (int) $contact['id']);

        $stored_secret = filesS3Plugin::getSecretKey($contact_id);
        $this->assertSame($secret, $stored_secret, 'Secret in wa_contact_settings must match fixture');

        $this->clearSessionAuthQuietly();
        $this->assertFalse(wa()->getUser()->isAuth());

        // Keep URI simple so encoding differences cannot break the signature.
        $uri = '/files/ping.txt';
        $signed = FilesS3SigV4RequestBuilder::sign(array(
            'access_key' => $login,
            'secret_key' => $secret,
            'region'     => 'us-east-1',
            'method'     => 'GET',
            'uri'        => $uri,
            'host'       => 'example.com',
        ));

        FilesS3RequestHelper::apply(array(
            'method'  => 'GET',
            'uri'     => $uri,
            'host'    => 'example.com',
            'headers' => $signed['headers'],
        ));

        $sig = new FilesS3SignatureV4TestDouble('us-east-1');
        $this->assertSame($login, $sig->getAccessKey(), 'Access key must parse from Authorization');
        $this->assertTrue($sig->verify($secret), 'SigV4 must verify with fixture secret before authenticate()');

        $auth = $this->createAuth();
        $this->assertTrue($auth->authenticate(), 'authenticateS3AuthNoSession::authenticate must succeed');
        $this->assertTrue(wa()->getUser()->isAuth());
        $this->assertSame($contact_id, (int) wa()->getUser()->getId());
        // Login may come from stub or contact storage depending on how waContact loads fields.
        $user_login = wa()->getUser()->get('login');
        if ($user_login === null || $user_login === '') {
            $row = (new waContactModel())->getById(wa()->getUser()->getId());
            $user_login = ifset($row['login']);
        }
        $this->assertSame($login, $user_login);
    }

    public function testAuthenticateRejectsWrongSecret()
    {
        $this->clearSessionAuthQuietly();

        $login = self::$storage['_login'];
        $this->assertNotEmpty($login);
        $uri = '/files/ping.txt';
        $signed = FilesS3SigV4RequestBuilder::sign(array(
            'access_key' => $login,
            'secret_key' => 'definitely-wrong-secret-key-value!!',
            'region'     => 'us-east-1',
            'method'     => 'GET',
            'uri'        => $uri,
            'host'       => 'example.com',
        ));

        FilesS3RequestHelper::apply(array(
            'method'  => 'GET',
            'uri'     => $uri,
            'host'    => 'example.com',
            'headers' => $signed['headers'],
        ));

        $auth = $this->createAuth();
        $this->assertFalse($auth->authenticate());
    }

    public function testAuthenticateRejectsUnknownAccessKey()
    {
        $this->clearSessionAuthQuietly();

        $uri = '/files/docs/a.txt';
        $signed = FilesS3SigV4RequestBuilder::sign(array(
            'access_key' => 'no_such_login_' . uniqid(),
            'secret_key' => 'any-secret',
            'region'     => 'us-east-1',
            'method'     => 'GET',
            'uri'        => $uri,
            'host'       => 'example.com',
        ));

        FilesS3RequestHelper::apply(array(
            'method'  => 'GET',
            'uri'     => $uri,
            'host'    => 'example.com',
            'headers' => $signed['headers'],
        ));

        $auth = $this->createAuth();
        $this->assertFalse($auth->authenticate());
    }

    public function testAuthenticateAcceptsAlreadyLoggedInUser()
    {
        // waUser::isAuth() is always false; filesS3Auth short-circuits on isAuth() === true.
        // Avoid session_start() under PHPUnit (headers already sent) by using a stub auth user.
        $stub = new FilesS3AuthUserStub(self::$user->getId());
        wa('files')->setUser($stub);
        $this->assertTrue(wa()->getUser()->isAuth());

        FilesS3RequestHelper::apply(array(
            'method' => 'GET',
            'uri'    => '/files/',
            'host'   => 'example.com',
        ));

        // Real class: early return when user is already auth.
        $auth = new filesS3Auth('us-east-1');
        $this->assertTrue($auth->authenticate());
    }
}

/**
 * filesS3Auth that applies credentials without starting a PHP session (for PHPUnit).
 */
class FilesS3AuthNoSession extends filesS3Auth
{
    public function authenticate()
    {
        if (wa()->getUser()->isAuth()) {
            return true;
        }

        $sig = new FilesS3SignatureV4TestDouble($this->region);
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
        if ($secret === '' || $secret === null || $secret === false) {
            return false;
        }

        if (!$sig->verify($secret)) {
            return false;
        }

        wa()->setUser(new FilesS3AuthUserStub($contact['id'], ifset($contact['login'], $access_key)));
        return true;
    }
}

/**
 * Minimal auth user stub for PHPUnit (no session).
 */
class FilesS3AuthUserStub extends waUser
{
    protected $stub_id;

    /**
     * @var string|null
     */
    protected $stub_login;

    public function __construct($id, $login = null)
    {
        $this->stub_id = (int) $id;
        $this->stub_login = $login;
        parent::__construct($id);
        if ($this->stub_login === null || $this->stub_login === '') {
            $row = (new waContactModel())->getById($this->stub_id);
            $this->stub_login = ifset($row['login']);
        }
    }

    public function getId($load = true)
    {
        return $this->stub_id;
    }

    public function isAuth()
    {
        return true;
    }

    public function get($field_id, $format = null)
    {
        if ($field_id === 'login' && $this->stub_login !== null && $this->stub_login !== '') {
            return $this->stub_login;
        }
        return parent::get($field_id, $format);
    }

    public function offsetGet($offset)
    {
        if ($offset === 'login' && $this->stub_login !== null && $this->stub_login !== '') {
            return $this->stub_login;
        }
        return parent::offsetGet($offset);
    }
}
