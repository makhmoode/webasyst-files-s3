<?php

/**
 * Test double for filesS3Backend: inject storages / root URL without DB init().
 */
class FilesS3BackendTestDouble extends filesS3Backend
{
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
