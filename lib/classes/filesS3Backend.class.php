<?php

class filesS3Backend
{
    /**
     * @var array
     */
    protected $settings;

    /**
     * @var string
     */
    protected $root_url = '/';

    /**
     * @var array
     */
    protected $storage_list = array();

    /**
     * @var int
     */
    protected $storage_id;

    /**
     * @var int
     */
    protected $parent_id;

    /**
     * @var array
     */
    protected $requested_node = array();

    /**
     * @var array
     */
    protected $last_folder = array();

    /**
     * @var bool
     */
    protected $last_folder_exists = false;

    public function __construct($settings = array())
    {
        $this->settings = $settings;
    }

    /**
     * @var string
     */
    protected $current_key = '';

    /**
     * @var bool
     */
    protected $is_folder_key = false;

    public function init()
    {
        $this->initRootUrl();
        $this->loadStorages();
    }

    /**
     * Settlement path prefix for path-style URLs (safe before auth).
     *
     * In non-root settlement mode the prefix must stay equal to the settlement
     * path (e.g. /files/). Using wa()->getRouteUrl('') here is unsafe: for
     * requests like /files/docs/ it may include the object key and then
     * stripRootPath eats the storage folder, so ListObjects sees an empty
     * prefix and returns the storage list again.
     */
    public function initRootUrl()
    {
        $settlement_path = $this->getSettlementBucketName();
        if ($settlement_path !== '') {
            $this->root_url = '/' . trim(str_replace('\\', '/', $settlement_path), '/') . '/';
            return;
        }
        $this->root_url = rtrim((string) wa()->getRouteUrl(''), '/') . '/';
    }

    /**
     * Load buckets visible to the current Files user. Must run after S3 auth
     * binds the real contact — otherwise limited storages are missing.
     */
    public function loadStorages()
    {
        $this->storage_list = array();
        $storage_model = new filesStorageModel();
        foreach ($storage_model->getAvailableStorages() as $storage) {
            $this->storage_list[$storage['name']] = $storage;
        }
    }

    /**
     * @return array
     */
    public function getBuckets()
    {
        $forced = $this->getSettlementBucketName();
        if ($forced !== '') {
            $created = null;
            foreach ($this->storage_list as $storage) {
                $dt = ifset($storage['create_datetime'], '');
                if ($created === null || ($dt !== '' && $dt < $created)) {
                    $created = $dt;
                }
            }
            return array(
                array(
                    'name'            => $forced,
                    'create_datetime' => $created ?: date('Y-m-d H:i:s'),
                ),
            );
        }

        $buckets = array();
        foreach ($this->storage_list as $storage) {
            $buckets[] = array(
                'name'            => $storage['name'],
                'create_datetime' => $storage['create_datetime'],
            );
        }
        return $buckets;
    }

    /**
     * @param string $bucket
     * @return bool
     */
    public function bucketExists($bucket)
    {
        $forced = $this->getSettlementBucketName();
        if ($forced !== '') {
            return $bucket === $forced;
        }
        return isset($this->storage_list[$bucket]);
    }

    /**
     * @param string $bucket
     * @param string $key
     * @return bool
     */
    public function resolveKey($bucket, $key)
    {
        if ($this->isSettlementBucketMode()) {
            if ($bucket === $this->getSettlementBucketName()) {
                return $this->resolveSettlementObjectKey($key);
            }
            // Internal re-resolve after ensureFolders may pass a storage name.
            if (isset($this->storage_list[$bucket])) {
                return $this->resolveStorageObjectKey($bucket, $key);
            }
            return false;
        }
        return $this->resolveStorageObjectKey($bucket, $key);
    }

    /**
     * Root multi-bucket mode: $bucket is a storage name.
     *
     * @param string $bucket
     * @param string $key
     * @return bool
     */
    protected function resolveStorageObjectKey($bucket, $key)
    {
        $this->current_key = ltrim($key, '/');
        $this->is_folder_key = $this->isFolderKey($this->current_key);
        if (!isset($this->storage_list[$bucket])) {
            return false;
        }

        $path = $this->current_key;
        if ($this->is_folder_key) {
            $path = rtrim($path, '/');
        }

        $this->parsePath($bucket, $path, $this->last_folder, $this->requested_node, $this->last_folder_exists);
        return true;
    }

    /**
     * Non-root settlement: one virtual bucket; object keys are storageName[/path...].
     *
     * @param string $key
     * @return bool
     */
    protected function resolveSettlementObjectKey($key)
    {
        $full = ltrim($this->decodeObjectPath($key), '/');
        if ($full === '') {
            $this->current_key = '';
            $this->is_folder_key = true;
            $this->storage_id = 0;
            $this->parent_id = 0;
            $root = $this->virtualBucketRootNode();
            $this->last_folder = $root;
            $this->requested_node = $root;
            $this->last_folder_exists = true;
            return true;
        }

        list($storage, $inner) = $this->splitSettlementObjectKey($full);
        if ($storage === '' || !isset($this->storage_list[$storage])) {
            return false;
        }

        return $this->resolveStorageObjectKey($storage, $inner);
    }

    /**
     * @param string $key full key inside the virtual settlement bucket
     * @return array [storage_name, key_within_storage]
     */
    public function splitSettlementObjectKey($key)
    {
        $key = ltrim($this->decodeObjectPath($key), '/');
        if ($key === '') {
            return array('', '');
        }
        $parts = explode('/', $key, 2);
        return array($parts[0], isset($parts[1]) ? $parts[1] : '');
    }

    /**
     * @return array
     */
    protected function virtualBucketRootNode()
    {
        $name = $this->getSettlementBucketName();
        return array(
            'id'              => 0,
            'name'            => $name,
            'type'            => 'folder',
            'size'            => 0,
            'create_datetime' => date('Y-m-d H:i:s'),
            'update_datetime' => date('Y-m-d H:i:s'),
            'is_storage'      => false,
            'is_virtual_root' => true,
            'storage_id'      => 0,
        );
    }

    /**
     * @param string $bucket
     * @param string $prefix
     * @param string $delimiter
     * @param int $max_keys
     * @param string $start_after
     * @return array
     */
    public function listObjects($bucket, $prefix = '', $delimiter = '', $max_keys = 1000, $start_after = '')
    {
        if (!$this->bucketExists($bucket)) {
            return null;
        }

        $prefix = $this->normalizePrefix($prefix);
        $max_keys = max(1, min(1000, (int) $max_keys));

        if ($this->isSettlementBucketMode() && $prefix === '') {
            return $this->listSettlementRootPrefixes($delimiter, $max_keys, $start_after);
        }

        if (!$this->resolveKey($bucket, $prefix)) {
            return array('items' => array(), 'common_prefixes' => array(), 'is_truncated' => false, 'next' => '');
        }

        // Prefix ending with '/' targets a folder (or implied prefix). List that folder's children.
        $folder_marker = null;
        if ($prefix !== '' && substr($prefix, -1) === '/') {
            if (empty($this->requested_node['id']) && empty($this->requested_node['is_storage'])) {
                return array('items' => array(), 'common_prefixes' => array(), 'is_truncated' => false, 'next' => '');
            }
            if (empty($this->requested_node['type']) || $this->requested_node['type'] !== 'folder') {
                return array('items' => array(), 'common_prefixes' => array(), 'is_truncated' => false, 'next' => '');
            }
            // Root multi-bucket: prefix that resolves to the storage itself is not a listable folder marker.
            // Settlement mode: prefix "storage/" is how clients open a storage folder inside the virtual bucket.
            if (!empty($this->requested_node['is_storage']) && !$this->isSettlementBucketMode()) {
                return array('items' => array(), 'common_prefixes' => array(), 'is_truncated' => false, 'next' => '');
            }
            $parent = $this->requested_node;
            // Cyberduck / AWS console folder placeholder: zero-size object named as the prefix.
            // Do not emit a marker for the storage root itself (prefix "docs/") — only for nested folders.
            if (empty($this->requested_node['is_storage'])) {
                $folder_marker = $this->nodeToListItem($prefix, $parent);
                $folder_marker['size'] = 0;
            }
        } else {
            $parent = $this->last_folder;
        }
        if (!$parent || !empty($parent['is_virtual_root'])) {
            return array('items' => array(), 'common_prefixes' => array(), 'is_truncated' => false, 'next' => '');
        }

        $list_sync = new filesS3ListSync();
        $list_sync->syncIfNeeded($parent);

        $fm = new filesS3FileModel();
        if (!empty($parent['is_storage'])) {
            $nodes = $fm->getAllByStorageId($parent['id']);
        } else {
            $nodes = $fm->getAllByParentId($parent['id']);
        }

        $items = array();
        $common_prefixes = array();

        if ($folder_marker) {
            if ($start_after === '' || strcmp($folder_marker['key'], $start_after) > 0) {
                $items[] = $folder_marker;
            }
        }

        foreach ($nodes as $node) {
            $relative = $prefix . $node['name'];
            if ($node['type'] === 'folder' && $delimiter !== '') {
                $common_prefixes[$prefix . $node['name'] . $delimiter] = true;
                continue;
            }
            if ($node['type'] === 'file') {
                if ($start_after !== '' && strcmp($relative, $start_after) <= 0) {
                    continue;
                }
                $items[] = $this->nodeToListItem($relative, $node);
            }
        }

        ksort($common_prefixes);
        $common_prefixes = array_keys($common_prefixes);
        usort($items, function ($a, $b) {
            return strcmp($a['key'], $b['key']);
        });

        $is_truncated = count($items) > $max_keys;
        if ($is_truncated) {
            $items = array_slice($items, 0, $max_keys);
        }
        $next = $is_truncated && $items ? end($items)['key'] : '';

        return array(
            'prefix'          => $prefix,
            'items'           => $items,
            'common_prefixes' => array_slice($common_prefixes, 0, $max_keys),
            'is_truncated'    => $is_truncated,
            'next'            => $next,
        );
    }

    /**
     * @param array $node
     * @return string
     */
    public function getEtag($node)
    {
        return md5($node['id'] . ':' . $node['size'] . ':' . ifempty($node['update_datetime'], $node['create_datetime']));
    }

    /**
     * @return array|null
     */
    public function getRequestedNode()
    {
        return $this->requested_node ?: null;
    }

    /**
     * @return array|null
     */
    public function headObject()
    {
        if (!$this->requested_node || empty($this->requested_node['id'])) {
            return null;
        }

        if ($this->requested_node['type'] === 'folder' || $this->is_folder_key) {
            return array(
                'size'          => 0,
                'etag'          => $this->getEtag($this->requested_node),
                'last_modified' => ifempty($this->requested_node['update_datetime'], $this->requested_node['create_datetime']),
                'content_type'  => 'application/x-directory',
            );
        }

        if ($this->requested_node['type'] !== 'file') {
            return null;
        }
        return array(
            'size'          => (int) $this->requested_node['size'],
            'etag'          => $this->getEtag($this->requested_node),
            'last_modified' => ifempty($this->requested_node['update_datetime'], $this->requested_node['create_datetime']),
            'content_type'  => waFiles::getMimeType($this->requested_node['name']),
        );
    }

    /**
     * @param int|null $offset
     * @param int|null $length
     * @return array|null
     */
    public function getObject($offset = null, $length = null)
    {
        $head = $this->headObject();
        if (!$head) {
            return null;
        }
        if ($this->requested_node['type'] === 'folder' || $this->is_folder_key) {
            return $head;
        }
        if (!isset($this->requested_node['source_id'])) {
            return null;
        }
        $source = filesSource::factory($this->requested_node['source_id']);
        if (!$source) {
            return null;
        }
        $stream = $source->download($this->requested_node, filesSource::DOWNLOAD_STREAM);
        if (!$stream) {
            return null;
        }
        $head['stream'] = $stream;
        $head['offset'] = $offset;
        $head['length'] = $length;
        return $head;
    }

    /**
     * @param resource $stream
     * @param int|null $content_length
     * @return bool
     */
    public function putObject($stream, $content_length = null)
    {
        if ($this->isFolderPut($content_length)) {
            return $this->putFolder($this->current_key);
        }

        // Storage must be known (resolveKey). Parent folders may be missing yet —
        // AWS CLI / SDKs PUT nested keys without creating prefix markers first.
        if (!$this->storage_id || !$this->last_folder) {
            return false;
        }

        if (!empty($this->requested_node['id']) && ifset($this->requested_node['type']) === 'file') {
            $data = $this->requested_node;
            if ($content_length !== null) {
                $data['size'] = $content_length;
            }
            return $this->replaceFileRecord($data, $stream);
        }

        $key = $this->current_key;
        if ($key === '') {
            return false;
        }

        $filename = basename($key);
        $dir = dirname($key);
        if ($dir !== '.' && $dir !== '') {
            if (!$this->ensureFolders($this->storage_id, $dir)) {
                return false;
            }
        } else {
            $this->parent_id = 0;
        }

        $file = array(
            'name'       => $filename,
            'size'       => $content_length,
            'parent_id'  => $this->parent_id,
            'storage_id' => $this->storage_id,
        );
        return $this->createFileRecord($file, $stream);
    }

    /**
     * @param array $file
     * @param resource $stream
     * @return bool
     */
    protected function createFileRecord(array $file, $stream)
    {
        $fm = new filesS3FileModel();
        return (bool) $fm->addFile($file, $stream);
    }

    /**
     * @param array $data
     * @param resource $stream
     * @return bool
     */
    protected function replaceFileRecord(array $data, $stream)
    {
        $fm = new filesS3FileModel();
        return (bool) $fm->replaceFile($data, $stream);
    }

    /**
     * S3 folder marker: key ending with '/' or empty body directory content-type.
     *
     * @param string $key
     * @return bool
     */
    protected function isFolderKey($key)
    {
        return $key !== '' && substr($key, -1) === '/';
    }

    /**
     * @param int|null $content_length
     * @return bool
     */
    protected function isFolderPut($content_length)
    {
        if ($this->is_folder_key) {
            return true;
        }

        $content_type = strtolower((string) waRequest::server('CONTENT_TYPE'));
        if (strpos($content_type, 'application/x-directory') !== false) {
            return true;
        }

        return false;
    }

    /**
     * @param string $key
     * @return bool
     */
    protected function putFolder($key)
    {
        $path = trim($key, '/');
        if ($path === '' || !$this->storage_id) {
            return false;
        }

        $segments = explode('/', $path);
        $folder_name = array_pop($segments);
        $parent_path = implode('/', $segments);

        if ($parent_path !== '') {
            if (!$this->ensureFolders($this->storage_id, $parent_path)) {
                return false;
            }
        } else {
            $this->parent_id = 0;
        }

        $parent_id = $this->parent_id;
        $fm = new filesS3FileModel();
        $existing = $fm->getByField(array(
            'name'       => $folder_name,
            'storage_id' => $this->storage_id,
            'parent_id'  => $parent_id,
        ));

        if ($existing) {
            return $existing['type'] === 'folder';
        }

        $id = $fm->addFolder(array(
            'name'       => $folder_name,
            'storage_id' => $this->storage_id,
            'parent_id'  => $parent_id,
        ));

        return (bool) $id;
    }

    /**
     * @return bool
     */
    public function deleteObject()
    {
        if (!$this->requested_node || empty($this->requested_node['id'])) {
            return false;
        }
        if (!empty($this->requested_node['is_storage'])) {
            return false;
        }
        if (ifempty($this->requested_node['type']) === 'folder') {
            $fm = new filesS3FileModel(null, true);
            return $fm->moveToTrash($this->requested_node['id']) !== false;
        }
        if ($this->requested_node['type'] !== 'file') {
            return false;
        }

        $id = (int) $this->requested_node['id'];
        $fm = new filesS3FileModel(null, true);
        $file = $fm->getById($id);
        if (!$file || $file['type'] !== 'file') {
            return false;
        }
        if ((int) $file['storage_id'] < 0) {
            return true;
        }

        $lm = new filesLockModel();
        $unlocked = $lm->sliceOffLocked(
            array($id),
            filesLockModel::RESOURCE_TYPE_FILE,
            filesLockModel::SCOPE_EXCLUSIVE,
            $fm->getOwner()
        );
        if (!$unlocked) {
            return false;
        }

        if ($fm->moveToTrash($id) === false) {
            return false;
        }

        $after = $fm->getById($id);
        if ($after && (int) $after['storage_id'] > 0) {
            return false;
        }

        return true;
    }

    /**
     * @param string $source_bucket
     * @param string $source_key
     * @return bool
     */
    public function copyObject($source_bucket, $source_key)
    {
        $backend = new self($this->settings);
        $backend->init();
        if (!$backend->resolveKey($source_bucket, $source_key)) {
            return false;
        }
        $source = $backend->getRequestedNode();
        if (!$source || $source['type'] !== 'file' || !empty($source['is_storage'])) {
            return false;
        }

        if (!$this->storage_id || !$this->last_folder) {
            return false;
        }

        $key = $this->current_key;
        if ($key === '') {
            return false;
        }

        $filename = basename($key);
        $dir = dirname($key);
        if ($dir !== '.' && $dir !== '') {
            if (!$this->ensureFolders($this->storage_id, $dir)) {
                return false;
            }
            $this->resolveKey($this->getBucketName(), $key);
        } else {
            $this->parent_id = 0;
        }

        if ($this->requested_node) {
            return false;
        }

        $fm = new filesS3FileModel(null, true);
        $res = $fm->copy($source['id'], $this->storage_id, $this->parent_id, array('is_async' => false));
        if ($res === false) {
            return false;
        }
        if (is_array($res) && !empty($res['process_id'])) {
            // copytask chunk size is derived from memory_limit; unlimited (-1) yields 0 and DivisionByZeroError.
            $this->ensureCopytaskMemoryLimit();
            try {
                filesCopytask::perform(10, array('process_id' => $res['process_id']));
            } catch (DivisionByZeroError $e) {
                waLog::log('filesS3 copyObject: copytask DivisionByZeroError: ' . $e->getMessage(), 'files/s3.log');
                return false;
            }
        }

        $copied_id = (is_array($res) && !empty($res['track_ids'][$source['id']])) ? $res['track_ids'][$source['id']] : null;
        if ($copied_id) {
            $copied = $fm->getById($copied_id);
            if ($copied && $copied['name'] !== $filename) {
                if ($fm->rename($copied_id, $filename) === false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Ensure Files copytask can compute a non-zero upload chunk size.
     */
    protected function ensureCopytaskMemoryLimit()
    {
        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '' || $limit === '-1') {
            @ini_set('memory_limit', '256M');
        }
        if (class_exists('filesConfig', false)) {
            try {
                $ref = new ReflectionProperty('filesConfig', 'memory_limit');
                $ref->setAccessible(true);
                $ref->setValue(null, null);
            } catch (ReflectionException $e) {
            }
        }
    }

    /**
     * @param string $bucket
     * @param array $keys
     * @return array
     */
    public function deleteObjects($bucket, $keys)
    {
        $deleted = array();
        $errors = array();
        foreach ($keys as $key) {
            if (!$this->resolveKey($bucket, $key)) {
                $errors[] = array('key' => $key, 'code' => 'NoSuchKey', 'message' => 'The specified key does not exist.');
                continue;
            }
            if ($this->deleteObject()) {
                $deleted[] = $key;
            } else {
                $errors[] = array('key' => $key, 'code' => 'InternalError', 'message' => 'Failed to delete object.');
            }
        }
        return array($deleted, $errors);
    }

    /**
     * @param string $bucket
     * @param string $key
     * @return string|false
     */
    public function initiateMultipartUpload($bucket, $key)
    {
        if (!$this->resolveKey($bucket, $key)) {
            return false;
        }

        if (!$this->storage_id) {
            return false;
        }

        $filename = basename($this->current_key);
        $dir = dirname($this->current_key);
        $parent_id = 0;
        $storage_id = $this->storage_id;

        if ($dir !== '.' && $dir !== '') {
            if (!$this->ensureFolders($storage_id, $dir)) {
                return false;
            }
            $this->resolveKey($bucket, $key);
            $parent_id = $this->parent_id;
        }

        $mm = $this->getMultipartModel();
        return $mm->createUpload(wa()->getUser()->getId(), $storage_id, $parent_id, $filename);
    }

    /**
     * @param string $upload_id
     * @param int $part_number
     * @param resource $stream
     * @param int $size
     * @return array etag on success, or array('error' => NoSuchUpload|AccessDenied|Conflict)
     */
    public function uploadPart($upload_id, $part_number, $stream, $size)
    {
        $owned = $this->resolveOwnedUpload($upload_id);
        if (isset($owned['error'])) {
            return $owned;
        }
        $mm = $owned['model'];

        $path = $mm->getPartPath($upload_id, $part_number);
        // waFiles::create() treats extensionless basenames as directories; only ensure parent dir.
        waFiles::create(dirname($path) . '/');
        if (is_dir($path)) {
            // Leftover from older buggy create($partPath) calls.
            waFiles::delete($path);
        }
        $dest = fopen($path, 'wb');
        if (!$dest) {
            return array('error' => 'Conflict');
        }
        stream_copy_to_stream($stream, $dest);
        fclose($dest);

        $etag = md5_file($path);
        $pm = $this->getMultipartPartModel();
        $pm->savePart($upload_id, $part_number, $size, $etag);
        return array('etag' => $etag);
    }

    /**
     * @param string $upload_id
     * @param array $parts array of ['PartNumber'=>, 'ETag'=>]
     * @return array|array{error:string}
     */
    public function completeMultipartUpload($upload_id, $parts)
    {
        $owned = $this->resolveOwnedUpload($upload_id);
        if (isset($owned['error'])) {
            return $owned;
        }
        $mm = $owned['model'];
        $upload = $owned['upload'];

        $assembled = $mm->getPartsDir($upload_id) . 'assembled';
        waFiles::create(dirname($assembled) . '/');
        if (is_dir($assembled)) {
            waFiles::delete($assembled);
        }
        $out = fopen($assembled, 'wb');
        if (!$out) {
            return array('error' => 'Conflict');
        }

        usort($parts, function ($a, $b) {
            return (int) $a['PartNumber'] - (int) $b['PartNumber'];
        });

        foreach ($parts as $part) {
            $part_path = $mm->getPartPath($upload_id, $part['PartNumber']);
            if (!file_exists($part_path)) {
                fclose($out);
                return array('error' => 'NoSuchUpload');
            }
            $in = fopen($part_path, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        $size = filesize($assembled);
        $stream = fopen($assembled, 'rb');
        $this->storage_id = $upload['storage_id'];
        $this->parent_id = $upload['parent_id'];
        $this->last_folder_exists = true;
        $this->last_folder = array(
            'id'         => $upload['parent_id'] ?: $upload['storage_id'],
            'storage_id' => $upload['storage_id'],
            'parent_id'  => $upload['parent_id'],
            'type'       => 'folder',
        );
        if ($upload['parent_id']) {
            $fm = new filesS3FileModel();
            $folder = $fm->getById($upload['parent_id']);
            if ($folder) {
                $this->last_folder = $folder;
            }
        } else {
            foreach ($this->storage_list as $name => $storage) {
                if ($storage['id'] == $upload['storage_id']) {
                    $this->last_folder = array(
                        'id'         => $storage['id'],
                        'name'       => $name,
                        'type'       => 'folder',
                        'is_storage' => true,
                        'storage_id' => $storage['id'],
                    );
                    break;
                }
            }
        }

        $this->requested_node = null;
        $fm = new filesS3FileModel();
        $existing = $fm->getByField(array(
            'name'       => $upload['filename'],
            'storage_id' => $upload['storage_id'],
            'parent_id'  => $upload['parent_id'],
        ));
        if ($existing && $existing['type'] === 'file') {
            $data = $existing;
            $data['size'] = $size;
            $file_id = $fm->replaceFile($data, $stream);
        } else {
            $file = array(
                'name'       => $upload['filename'],
                'size'       => $size,
                'parent_id'  => $upload['parent_id'],
                'storage_id' => $upload['storage_id'],
            );
            $file_id = $fm->addFile($file, $stream);
        }
        fclose($stream);

        $mm->deleteUpload($upload_id);
        @unlink($assembled);

        if (!$file_id) {
            return array('error' => 'Conflict');
        }
        $node = $fm->getById($file_id);
        return array(
            'etag' => $this->getEtag($node),
            'key'  => $this->buildKeyForNode($node),
        );
    }

    /**
     * @param string $upload_id
     * @return true|array{error:string}
     */
    public function abortMultipartUpload($upload_id)
    {
        $owned = $this->resolveOwnedUpload($upload_id);
        if (isset($owned['error'])) {
            return $owned;
        }
        $owned['model']->deleteUpload($upload_id);
        return true;
    }

    /**
     * @param string $upload_id
     * @return array|array{error:string}
     */
    public function listParts($upload_id)
    {
        $owned = $this->resolveOwnedUpload($upload_id);
        if (isset($owned['error'])) {
            return $owned;
        }
        $pm = $this->getMultipartPartModel();
        return $pm->getParts($upload_id);
    }

    /**
     * Load multipart upload and verify ownership for the current user.
     *
     * @param string $upload_id
     * @return array{upload:array,model:filesS3MultipartModel}|array{error:string}
     */
    public function resolveOwnedUpload($upload_id)
    {
        $mm = $this->getMultipartModel();
        $upload = $mm->getUpload($upload_id);
        if (!$upload) {
            return array('error' => 'NoSuchUpload');
        }
        if ((int) $upload['contact_id'] !== (int) wa()->getUser()->getId()) {
            return array('error' => 'AccessDenied');
        }
        return array(
            'upload' => $upload,
            'model'  => $mm,
        );
    }

    /**
     * @return filesS3MultipartModel
     */
    protected function getMultipartModel()
    {
        return new filesS3MultipartModel();
    }

    /**
     * @return filesS3MultipartPartModel
     */
    protected function getMultipartPartModel()
    {
        return new filesS3MultipartPartModel();
    }

    /**
     * @param string $path
     * @return string
     */
    public function stripRootPath($path)
    {
        $path = preg_replace('~\?.*$~', '', $path);
        $parsed = parse_url($path, PHP_URL_PATH);
        if ($parsed !== null && $parsed !== false) {
            $path = $parsed;
        }
        $path = $this->decodeObjectPath($path);
        $root = trim($this->root_url, '/');
        if ($root !== '') {
            $path = preg_replace('~^/?' . preg_quote($root, '~') . '/?~iu', '', $path);
        }
        return ltrim($path, '/');
    }

    /**
     * Parse path-style S3 URL into [bucket, key].
     *
     * Non-root settlement: bucket is always the settlement path; key is the remainder
     * after stripping one or more leading "{settlement}/" segments (clients may repeat
     * the bucket name). Independent of wa()->getRouteUrl('').
     *
     * @param string $request_path REQUEST_URI or framework request path
     * @return array [bucket, key]
     */
    public function parsePathStyleRequest($request_path)
    {
        $path = preg_replace('~\?.*$~', '', (string) $request_path);
        $parsed = parse_url($path, PHP_URL_PATH);
        if ($parsed !== false && $parsed !== null && $parsed !== '') {
            $path = $parsed;
        }
        $path = trim(str_replace('\\', '/', $this->decodeObjectPath($path)), '/');

        $forced = $this->getSettlementBucketName();
        if ($forced !== '') {
            $forced = trim(str_replace('\\', '/', $forced), '/');
            $key = $path;
            for ($i = 0; $i < 2; $i++) {
                if ($key === $forced) {
                    $key = '';
                    break;
                }
                $prefix = $forced . '/';
                if ($key !== '' && strpos($key, $prefix) === 0) {
                    $key = substr($key, strlen($prefix));
                    continue;
                }
                break;
            }
            return array($forced, $key);
        }

        $this->initRootUrl();
        return $this->splitBucketAndKey($this->stripRootPath('/' . $path));
    }

    /**
     * @param string $relative
     * @return array [bucket, key]
     */
    public function splitBucketAndKey($relative)
    {
        $relative = ltrim($this->decodeObjectPath($relative), '/');
        $forced = $this->getSettlementBucketName();
        if ($forced !== '') {
            return $this->parsePathStyleRequest($relative);
        }

        if ($relative === '') {
            return array('', '');
        }

        $best_bucket = '';
        $best_key = '';
        foreach ($this->storage_list as $name => $storage) {
            if ($relative === $name) {
                return array($name, '');
            }
            $prefix = $name . '/';
            if (strpos($relative, $prefix) === 0 && strlen($name) >= strlen($best_bucket)) {
                $best_bucket = $name;
                $best_key = substr($relative, strlen($prefix));
            }
        }
        if ($best_bucket !== '') {
            return array($best_bucket, $best_key);
        }

        $parts = explode('/', $relative, 2);
        return array($parts[0], isset($parts[1]) ? $parts[1] : '');
    }

    /**
     * Non-root settlement path used as the sole S3 bucket name (empty = root multi-bucket mode).
     *
     * @return string
     */
    public function getSettlementBucketName()
    {
        if (!empty($this->settings['settlement'])) {
            return filesS3Plugin::getSettlementPath($this->settings['settlement']);
        }
        return '';
    }

    /**
     * @return bool
     */
    public function isSettlementBucketMode()
    {
        return $this->getSettlementBucketName() !== '';
    }

    /**
     * List storages as CommonPrefixes inside the virtual settlement bucket.
     *
     * @param string $delimiter
     * @param int $max_keys
     * @param string $start_after
     * @return array
     */
    protected function listSettlementRootPrefixes($delimiter, $max_keys, $start_after)
    {
        $delim = $delimiter !== '' ? $delimiter : '/';
        $common_prefixes = array();
        foreach ($this->storage_list as $name => $storage) {
            $prefix = $name . $delim;
            if ($start_after !== '' && strcmp($prefix, $start_after) <= 0) {
                continue;
            }
            $common_prefixes[] = $prefix;
        }
        sort($common_prefixes, SORT_STRING);

        $is_truncated = count($common_prefixes) > $max_keys;
        if ($is_truncated) {
            $common_prefixes = array_slice($common_prefixes, 0, $max_keys);
        }
        $next = $is_truncated && $common_prefixes ? end($common_prefixes) : '';

        return array(
            'items'           => array(),
            'common_prefixes' => $common_prefixes,
            'is_truncated'     => $is_truncated,
            'next'            => $next,
        );
    }

    /**
     * @param string $path
     * @return string
     */
    public function decodeObjectPath($path)
    {
        if ($path === '') {
            return '';
        }
        if (preg_match('~%[0-9A-Fa-f]{2}~', $path)) {
            return rawurldecode($path);
        }
        return $path;
    }

    /**
     * Normalize x-amz-copy-source to bucket/key relative path.
     *
     * @param string $copy_source
     * @return string
     */
    public function normalizeCopySource($copy_source)
    {
        $copy_source = trim($copy_source);
        if ($copy_source === '') {
            return '';
        }

        if (preg_match('#^https?://[^/]+(/.*)$#i', $copy_source, $m)) {
            $copy_source = $m[1];
        }

        $copy_source = ltrim($this->decodeObjectPath($copy_source), '/');

        $settlement = '';
        if (!empty($this->settings['settlement'])) {
            $settlement = preg_replace(array('/:\d+/', '/\*$/'), '', $this->settings['settlement']);
            $settlement = trim($settlement, '/');
        }

        if ($settlement !== '') {
            if (stripos($copy_source, $settlement . '/') === 0) {
                $copy_source = substr($copy_source, strlen($settlement) + 1);
            } elseif (strcasecmp($copy_source, $settlement) === 0) {
                $copy_source = '';
            }
        }

        $root = trim($this->root_url, '/');
        if ($root !== '' && $copy_source !== '') {
            if (stripos($copy_source, $root . '/') === 0) {
                $copy_source = substr($copy_source, strlen($root) + 1);
            } elseif (strcasecmp($copy_source, $root) === 0) {
                $copy_source = '';
            }
        }

        return $copy_source;
    }

    /**
     * @param string $bucket
     * @param string $path
     * @param array $last_folder
     * @param array $node
     * @param bool $last_folder_exists
     */
    protected function parsePath($bucket, $path, &$last_folder, &$node, &$last_folder_exists)
    {
        $node = array();
        $last_folder = array();
        $last_folder_exists = false;
        $this->requested_node = array();
        $this->last_folder = array();
        $this->last_folder_exists = false;

        if (!isset($this->storage_list[$bucket])) {
            return;
        }

        $storage = $this->storage_list[$bucket];
        $node = array(
            'id'              => $storage['id'],
            'name'            => $bucket,
            'type'            => 'folder',
            'size'            => 0,
            'create_datetime' => $storage['create_datetime'],
            'update_datetime' => $storage['create_datetime'],
            'is_storage'      => true,
            'storage_id'      => $storage['id'],
        );
        $last_folder = $node;
        $this->storage_id = $node['id'];
        $this->parent_id = 0;

        $path = trim($path, '/');
        if ($path === '') {
            $last_folder_exists = true;
            $this->last_folder = $last_folder;
            $this->last_folder_exists = true;
            return;
        }

        $segments = explode('/', $path);
        $count = count($segments);
        $fm = new filesS3FileModel();

        for ($i = 0; $i < $count; $i++) {
            $child = $fm->getByField(array(
                'name'       => $segments[$i],
                'storage_id' => $this->storage_id,
                'parent_id'  => $this->parent_id,
            ));
            if (!$child) {
                break;
            }
            $node = $child;
            if ($child['type'] === 'folder') {
                $last_folder = $child;
                $this->parent_id = $child['id'];
            }
        }

        $last_folder_exists = ($i >= $count);
        if (!$last_folder_exists && $i === $count - 1 && $last_folder) {
            $last_folder_exists = true;
            $node = array();
        }

        $this->requested_node = $node;
        $this->last_folder = $last_folder;
        $this->last_folder_exists = $last_folder_exists;
    }

    /**
     * @param int $storage_id
     * @param string $dir_path
     * @return bool
     */
    protected function ensureFolders($storage_id, $dir_path)
    {
        $segments = array_filter(explode('/', trim($dir_path, '/')), 'strlen');
        $parent_id = 0;
        $fm = new filesS3FileModel();

        foreach ($segments as $segment) {
            $folder = $fm->getByField(array(
                'name'       => $segment,
                'storage_id' => $storage_id,
                'parent_id'  => $parent_id,
            ));
            if ($folder) {
                if ($folder['type'] !== 'folder') {
                    return false;
                }
                $parent_id = $folder['id'];
                continue;
            }
            $id = $fm->addFolder(array(
                'name'       => $segment,
                'storage_id' => $storage_id,
                'parent_id'  => $parent_id,
            ));
            if (!$id) {
                return false;
            }
            $parent_id = $id;
        }

        $this->parent_id = $parent_id;
        $this->storage_id = $storage_id;
        return true;
    }

    /**
     * @param string $key
     * @param array $node
     * @return array
     */
    protected function nodeToListItem($key, $node)
    {
        return array(
            'key'           => $key,
            'size'          => (int) ifset($node['size'], 0),
            'etag'          => $this->getEtag($node),
            'last_modified' => ifempty($node['update_datetime'], $node['create_datetime']),
        );
    }

    /**
     * @param string $prefix
     * @return string
     */
    protected function normalizePrefix($prefix)
    {
        $prefix = ltrim($prefix, '/');
        if ($prefix !== '' && substr($prefix, -1) !== '/') {
            $last = basename($prefix);
            if (strpos($last, '.') !== false) {
                return $prefix;
            }
        }
        return $prefix === '' ? '' : rtrim($prefix, '/') . '/';
    }

    /**
     * @return string
     */
    protected function currentKey()
    {
        if (!$this->requested_node) {
            return '';
        }
        if (!empty($this->requested_node['is_storage'])) {
            return '';
        }
        if ($this->requested_node['type'] === 'file') {
            return $this->buildKeyForNode($this->requested_node);
        }
        return '';
    }

    /**
     * @return string
     */
    protected function getBucketName()
    {
        foreach ($this->storage_list as $name => $storage) {
            if ($storage['id'] == $this->storage_id) {
                return $name;
            }
        }
        return '';
    }

    /**
     * @param array $node
     * @return string
     */
    protected function buildKeyForNode($node)
    {
        $fm = new filesS3FileModel();
        $parts = array($node['name']);
        $parent_id = $node['parent_id'];
        while ($parent_id) {
            $parent = $fm->getById($parent_id);
            if (!$parent) {
                break;
            }
            array_unshift($parts, $parent['name']);
            $parent_id = $parent['parent_id'];
        }
        return implode('/', $parts);
    }
}
