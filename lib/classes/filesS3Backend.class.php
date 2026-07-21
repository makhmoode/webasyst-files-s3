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
        $this->root_url = rtrim((string) wa()->getRouteUrl(''), '/') . '/';
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
        return isset($this->storage_list[$bucket]);
    }

    /**
     * @param string $bucket
     * @param string $key
     * @return bool
     */
    public function resolveKey($bucket, $key)
    {
        $this->current_key = ltrim($key, '/');
        $this->is_folder_key = $this->isFolderKey($this->current_key);
        if (!$this->bucketExists($bucket)) {
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

        $this->resolveKey($bucket, $prefix);

        // Prefix ending with '/' targets a folder (or implied prefix). List that folder's children.
        $folder_marker = null;
        if ($prefix !== '' && substr($prefix, -1) === '/') {
            if (empty($this->requested_node['id']) || $this->requested_node['type'] !== 'folder'
                || !empty($this->requested_node['is_storage'])
            ) {
                return array('items' => array(), 'common_prefixes' => array(), 'is_truncated' => false, 'next' => '');
            }
            $parent = $this->requested_node;
            // Cyberduck / AWS console folder placeholder: zero-size object named as the prefix.
            $folder_marker = $this->nodeToListItem($prefix, $parent);
            $folder_marker['size'] = 0;
        } else {
            $parent = $this->last_folder;
        }
        if (!$parent) {
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

        if (!$this->last_folder_exists || !$this->last_folder) {
            return false;
        }

        if ($this->requested_node && $this->requested_node['type'] === 'file') {
            $fm = new filesS3FileModel();
            $data = $this->requested_node;
            if ($content_length !== null) {
                $data['size'] = $content_length;
            }
            return (bool) $fm->replaceFile($data, $stream);
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
        }

        $fm = new filesS3FileModel();
        $file = array(
            'name'       => $filename,
            'size'       => $content_length,
            'parent_id'  => $this->parent_id,
            'storage_id' => $this->storage_id,
        );
        return (bool) $fm->addFile($file, $stream);
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

        if (!$this->last_folder_exists || !$this->last_folder) {
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

        $filename = basename($key);
        $dir = dirname($key);
        $parent_id = 0;
        $storage_id = $this->storage_list[$bucket]['id'];

        if ($dir !== '.' && $dir !== '') {
            if (!$this->ensureFolders($storage_id, $dir)) {
                return false;
            }
            $this->resolveKey($bucket, $dir);
            $parent_id = $this->parent_id;
        }

        $mm = new filesS3MultipartModel();
        return $mm->createUpload(wa()->getUser()->getId(), $storage_id, $parent_id, $filename);
    }

    /**
     * @param string $upload_id
     * @param int $part_number
     * @param resource $stream
     * @param int $size
     * @return string|false
     */
    public function uploadPart($upload_id, $part_number, $stream, $size)
    {
        $mm = new filesS3MultipartModel();
        $upload = $mm->getUpload($upload_id, wa()->getUser()->getId());
        if (!$upload) {
            return false;
        }

        $path = $mm->getPartPath($upload_id, $part_number);
        waFiles::create($path);
        $dest = fopen($path, 'wb');
        if (!$dest) {
            return false;
        }
        stream_copy_to_stream($stream, $dest);
        fclose($dest);

        $etag = md5_file($path);
        $pm = new filesS3MultipartPartModel();
        $pm->savePart($upload_id, $part_number, $size, $etag);
        return $etag;
    }

    /**
     * @param string $upload_id
     * @param array $parts array of ['PartNumber'=>, 'ETag'=>]
     * @return array|false
     */
    public function completeMultipartUpload($upload_id, $parts)
    {
        $mm = new filesS3MultipartModel();
        $upload = $mm->getUpload($upload_id, wa()->getUser()->getId());
        if (!$upload) {
            return false;
        }

        $assembled = $mm->getPartsDir($upload_id) . 'assembled';
        waFiles::create($assembled);
        $out = fopen($assembled, 'wb');
        if (!$out) {
            return false;
        }

        usort($parts, function ($a, $b) {
            return (int) $a['PartNumber'] - (int) $b['PartNumber'];
        });

        foreach ($parts as $part) {
            $part_path = $mm->getPartPath($upload_id, $part['PartNumber']);
            if (!file_exists($part_path)) {
                fclose($out);
                return false;
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
            return false;
        }
        $node = $fm->getById($file_id);
        return array(
            'etag' => $this->getEtag($node),
            'key'  => $this->buildKeyForNode($node),
        );
    }

    /**
     * @param string $upload_id
     * @return bool
     */
    public function abortMultipartUpload($upload_id)
    {
        $mm = new filesS3MultipartModel();
        $upload = $mm->getUpload($upload_id, wa()->getUser()->getId());
        if (!$upload) {
            return false;
        }
        $mm->deleteUpload($upload_id);
        return true;
    }

    /**
     * @param string $upload_id
     * @return array|false
     */
    public function listParts($upload_id)
    {
        $mm = new filesS3MultipartModel();
        $upload = $mm->getUpload($upload_id, wa()->getUser()->getId());
        if (!$upload) {
            return false;
        }
        $pm = new filesS3MultipartPartModel();
        return $pm->getParts($upload_id);
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
     * @param string $relative
     * @return array [bucket, key]
     */
    public function splitBucketAndKey($relative)
    {
        $relative = ltrim($this->decodeObjectPath($relative), '/');
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
        $segments = array_filter(explode('/', trim($dir_path, '/')));
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
