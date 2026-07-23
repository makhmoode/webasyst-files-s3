<?php

/**
 * Integration base: creates a Files user/storage and cleans up after class.
 *
 * @group long_tests
 */
abstract class FilesS3IntegrationTestCase extends FilesS3TestCase
{
    /**
     * @var array
     */
    protected static $resources = array();

    /**
     * @var filesModel
     */
    protected $fm;

    /**
     * @var array|null
     */
    protected static $storage;

    /**
     * @var waUser|null
     */
    protected static $user;

    /**
     * @var string|null
     */
    protected static $skip_reason = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (empty($GLOBALS['files_s3_files_app_ready'])) {
            self::$skip_reason = 'Files app unavailable (DB required): '
                . ifset($GLOBALS['files_s3_files_app_error'], 'unknown');
            return;
        }
        wa('contacts');
        wa('files');

        // Files copytask uses getMemoryLimit()/2 as chunk size; unlimited (-1) becomes 0 and divides by zero.
        @ini_set('memory_limit', '256M');
        self::resetFilesMemoryLimitCache();

        $login = 's3test_' . uniqid();
        $contact = new waContact();
        $contact['firstname'] = 'S3Unit';
        $contact['lastname'] = 'Tester' . rand(1000, 9999);
        $contact['is_user'] = 1;
        $contact->setPassword('S3TestPass!' . rand(1000, 9999));
        $errors = $contact->save();
        if ($errors) {
            self::$skip_reason = 'Could not create test contact: ' . json_encode($errors);
            return;
        }
        // Persist login via model update — same pattern as waAuthFindByPhoneLoginTest.
        // Assigning $contact['login'] before save() is unreliable for DB lookup.
        $cm = new waContactModel();
        $cm->updateById($contact->getId(), array('login' => $login));
        $stored = $cm->getById($contact->getId());
        if (!$stored || ifset($stored['login']) !== $login) {
            self::$skip_reason = 'Could not persist contact login=' . $login;
            return;
        }
        self::markForClean($contact->getId(), 'contact');

        $crm = new waContactRightsModel();
        $crm->save($contact->getId(), 'webasyst', 'backend', 1);
        $crm->save($contact->getId(), 'files', 'backend', 1);

        self::$user = new waUser($contact->getId());
        wa('files')->setUser(self::$user);

        $secret = filesS3Plugin::regenerateSecretKey($contact->getId());
        self::markForClean(array(
            'contact_id' => $contact->getId(),
            'app_id'     => filesS3Plugin::CONTACT_SETTINGS_APP,
        ), 'contact_settings');

        $sm = new filesStorageModel();
        $storage_name = 'S3TestBucket_' . uniqid();
        $storage_id = $sm->add(array(
            'access_type' => filesStorageModel::ACCESS_TYPE_EVERYONE,
            'name'        => $storage_name,
        ));
        self::markForClean($storage_id, 'storage');

        $source = filesSource::factoryApp();
        $source->mount(array(
            'storage_id' => $storage_id,
            'folder_id'  => null,
        ));
        $source = $source->save();
        if ($source && $source->getId()) {
            self::markForClean($source->getId(), 'source');
        }

        self::$storage = $sm->getStorage($storage_id, true, true);
        self::$storage['_secret'] = $secret;
        self::$storage['_login'] = $login;
        self::$storage['_contact_id'] = $contact->getId();
    }

    /**
     * Clear cached Files memory_limit so chunk sizing picks up the test ini value.
     */
    protected static function resetFilesMemoryLimitCache()
    {
        if (!class_exists('filesConfig', false)) {
            return;
        }
        try {
            $ref = new ReflectionProperty('filesConfig', 'memory_limit');
            $ref->setAccessible(true);
            $ref->setValue(null, null);
        } catch (ReflectionException $e) {
        }
    }

    public function setUp(): void
    {
        parent::setUp();
        if (self::$skip_reason) {
            $this->markTestSkipped(self::$skip_reason);
        }
        @ini_set('memory_limit', '256M');
        self::resetFilesMemoryLimitCache();
        $this->fm = new filesModel();
        if (self::$user) {
            wa('files')->setUser(self::$user);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$skip_reason) {
            parent::tearDownAfterClass();
            return;
        }
        self::clearFiles();
        self::clearStorages();
        self::clearContactSettings();
        self::clearContacts();
        parent::tearDownAfterClass();
    }

    /**
     * @param mixed $item
     * @param string $type
     */
    protected static function markForClean($item, $type)
    {
        if (!isset(self::$resources[$type])) {
            self::$resources[$type] = array();
        }
        self::$resources[$type][] = $item;
    }

    /**
     * @param array|string $keys
     * @param bool $unset
     * @return array
     */
    protected static function receiveResources($keys, $unset = true)
    {
        $resources = array();
        foreach ((array) $keys as $key) {
            foreach ((array) ifset(self::$resources[$key]) as $resource) {
                $resources[] = $resource;
                if ($unset) {
                    unset(self::$resources[$key]);
                }
            }
        }
        return $resources;
    }

    /**
     * @param string $name
     * @param int $parent_id
     * @return array
     */
    protected function addFolder($name, $parent_id = 0)
    {
        $fm = new filesS3FileModel();
        $id = $fm->addFolder(array(
            'name'       => $name,
            'storage_id' => self::$storage['id'],
            'parent_id'  => $parent_id,
        ));
        self::markForClean($id, 'file');
        return $fm->getById($id);
    }

    /**
     * @param string $name
     * @param string $contents
     * @param int $parent_id
     * @return array
     */
    protected function addFileNode($name, $contents = 'hello', $parent_id = 0)
    {
        $tmp = tempnam(sys_get_temp_dir(), 's3test_');
        file_put_contents($tmp, $contents);
        $stream = fopen($tmp, 'rb');

        $fm = new filesS3FileModel();
        $file = array(
            'name'       => $name,
            'size'       => strlen($contents),
            'parent_id'  => $parent_id,
            'storage_id' => self::$storage['id'],
        );
        $id = $fm->addFile($file, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        @unlink($tmp);

        self::markForClean($id, 'file');
        return $fm->getById($id);
    }

    /**
     * @return filesS3Backend
     */
    protected function createBackend()
    {
        $backend = new filesS3Backend(array(
            'region'     => 'us-east-1',
            'settlement' => 'example.com/*',
        ));
        $backend->init();
        return $backend;
    }

    protected static function clearFiles()
    {
        $files = self::receiveResources(array('file', 'files'));
        $ids = array();
        foreach ($files as $file) {
            if (wa_is_int($file)) {
                $ids[] = (int) $file;
            } elseif (is_array($file) && isset($file['id'])) {
                $ids[] = (int) $file['id'];
            }
        }
        $ids = array_filter(array_unique($ids));
        if ($ids) {
            $fm = new filesS3FileModel(null, true);
            foreach ($ids as $id) {
                try {
                    $fm->moveToTrash($id);
                } catch (Exception $e) {
                }
                try {
                    $fm->deleteById($id);
                } catch (Exception $e) {
                }
            }
        }
    }

    protected static function clearSources()
    {
        $sources = self::receiveResources(array('source', 'sources'));
        $sm = new filesSourceModel();
        foreach ($sources as $source) {
            $id = wa_is_int($source) ? (int) $source : (int) ifset($source['id']);
            if ($id > 0) {
                try {
                    $source_obj = filesSource::factory($id);
                    if ($source_obj) {
                        $source_obj->delete();
                    } else {
                        $sm->deleteById($id);
                    }
                } catch (Exception $e) {
                }
            }
        }
    }

    protected static function clearStorages()
    {
        self::clearSources();
        $storages = self::receiveResources(array('storage', 'storages'));
        $sm = new filesStorageModel();
        foreach ($storages as $storage) {
            $id = wa_is_int($storage) ? (int) $storage : (int) ifset($storage['id']);
            if ($id > 0) {
                try {
                    $sm->delete($id);
                } catch (Exception $e) {
                }
            }
        }
    }

    protected static function clearContactSettings()
    {
        $rows = self::receiveResources('contact_settings');
        $csm = new waContactSettingsModel();
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['contact_id'], $row['app_id'])) {
                $csm->deleteByField(array(
                    'contact_id' => $row['contact_id'],
                    'app_id'     => $row['app_id'],
                ));
            }
        }
    }

    protected static function clearContacts()
    {
        $contacts = self::receiveResources(array('contact', 'contacts'));
        $ids = array();
        foreach ($contacts as $contact) {
            if (wa_is_int($contact) && $contact > 1) {
                $ids[] = (int) $contact;
            } elseif ($contact instanceof waContact && $contact->getId() > 1) {
                $ids[] = $contact->getId();
            }
        }
        $ids = array_unique($ids);
        if ($ids) {
            $cm = new waContactModel();
            $cm->delete($ids, false);
        }
    }
}
