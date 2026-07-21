<?php

/**
 * Test double for filesS3Backend: inject storages / root URL without DB init().
 */
class FilesS3BackendTestDouble extends filesS3Backend
{
    /**
     * @var array
     */
    public $ensure_folders_calls = array();

    /**
     * @var array|null
     */
    public $last_create_file = null;

    /**
     * @var array|null
     */
    public $last_replace_file = null;

    /**
     * @param array $storages name => storage row (id, name, create_datetime, ...)
     * @param string $root_url
     * @param array $settings
     */
    public function __construct(array $storages = array(), $root_url = '/', array $settings = array())
    {
        parent::__construct($settings);
        $this->storage_list = $storages;
        $this->root_url = $root_url;
    }

    /**
     * Skip DB storage load.
     */
    public function init()
    {
        // no-op for unit tests
    }

    /**
     * @param string $prefix
     * @return string
     */
    public function exposeNormalizePrefix($prefix)
    {
        return $this->normalizePrefix($prefix);
    }

    /**
     * @param array $storages
     */
    public function setStorageList(array $storages)
    {
        $this->storage_list = $storages;
    }

    /**
     * @param string $root_url
     */
    public function setRootUrl($root_url)
    {
        $this->root_url = $root_url;
    }

    /**
     * @param int $storage_id
     * @param array $last_folder
     * @param bool $last_folder_exists
     * @param array $requested_node
     * @param string $current_key
     */
    public function seedResolveState($storage_id, array $last_folder, $last_folder_exists, array $requested_node, $current_key)
    {
        $this->storage_id = $storage_id;
        $this->last_folder = $last_folder;
        $this->last_folder_exists = $last_folder_exists;
        $this->requested_node = $requested_node;
        $this->current_key = $current_key;
        $this->parent_id = 0;
    }

    protected function ensureFolders($storage_id, $dir_path)
    {
        $this->ensure_folders_calls[] = array($storage_id, $dir_path);
        $this->parent_id = 42;
        $this->storage_id = $storage_id;
        return true;
    }

    protected function createFileRecord(array $file, $stream)
    {
        $this->last_create_file = $file;
        return true;
    }

    protected function replaceFileRecord(array $data, $stream)
    {
        $this->last_replace_file = $data;
        return true;
    }

    /**
     * @param string $bucket
     * @param string $key
     * @return string
     */
    public function initiateMultipartUpload($bucket, $key)
    {
        return 'test-upload-id';
    }

    /**
     * @param string $bucket
     * @param array $keys
     * @return array
     */
    public function deleteObjects($bucket, $keys)
    {
        return array(array(), array());
    }
}
