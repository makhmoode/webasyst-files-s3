<?php

/**
 * On-demand external source sync before S3 ListObjects responses.
 */
class filesS3ListSync
{
    const CACHE_ID = 'plugins/s3/list_sync_times';

    /**
     * TTL in seconds for list-triggered on-demand source sync.
     */
    const LIST_SYNC_TTL = 120;

    /**
     * @var int
     */
    protected $sync_count = 0;

    /**
     * @return int
     */
    public function getSyncCount()
    {
        return $this->sync_count;
    }

    /**
     * TTL in seconds for cached sync results.
     *
     * @return int
     */
    public function getSyncTtl()
    {
        return self::LIST_SYNC_TTL;
    }

    /**
     * Sync external source folder/storage before listing when source is on-demand.
     *
     * @param array $parent Resolved storage root or folder node from filesS3Backend.
     */
    public function syncIfNeeded($parent)
    {
        if (!$parent) {
            return;
        }

        $descriptor = $this->buildDescriptor($parent);
        if ($descriptor) {
            $this->syncDescriptor($descriptor);
        }
    }

    /**
     * @param array $descriptor
     */
    protected function syncDescriptor(array $descriptor)
    {
        $source = filesSource::factory($descriptor['source_id']);
        if (!$source || !$source->isOnDemand()) {
            return;
        }

        $cache_key = $descriptor['cache_key'];
        if (!$this->isSyncDue($cache_key)) {
            return;
        }

        try {
            $this->runSync($source, $descriptor['context']);
            $this->markSynced($cache_key);
            $this->sync_count++;
        } catch (Exception $e) {
            waLog::log(
                'filesS3 list sync failed for ' . $cache_key . ': ' . $e->getMessage(),
                'files/s3.log'
            );
        }
    }

    /**
     * @param array $parent
     * @return array|null
     */
    protected function buildDescriptor($parent)
    {
        if (!empty($parent['is_storage'])) {
            $storage_id = (int) ifset($parent['id'], 0);
            if ($storage_id <= 0) {
                return null;
            }
            $sm = new filesStorageModel();
            $storage = $sm->getStorage($storage_id, true);
            if (!$storage) {
                return null;
            }
            $source_id = abs((int) ifset($storage['source_id'], 0));
            if ($source_id <= 0) {
                return null;
            }
            return array(
                'source_id'  => $source_id,
                'cache_key'  => $this->buildCacheKey($source_id, 'storage', $storage_id),
                'context'    => array('storage' => $storage),
            );
        }

        $folder_id = (int) ifset($parent['id'], 0);
        if ($folder_id <= 0 || ifset($parent['type']) !== 'folder') {
            return null;
        }

        $fm = new filesFileModel();
        $folder = $fm->getItem($folder_id, false);
        if (!$folder || $folder['type'] !== 'folder') {
            return null;
        }

        $source_id = abs((int) ifset($folder['source_id'], 0));
        if ($source_id <= 0) {
            return null;
        }

        return array(
            'source_id'  => $source_id,
            'cache_key'  => $this->buildCacheKey($source_id, 'folder', $folder_id),
            'context'    => array('folder' => $folder),
        );
    }

    /**
     * @param int $source_id
     * @param string $type storage|folder
     * @param int $node_id
     * @return string
     */
    protected function buildCacheKey($source_id, $type, $node_id)
    {
        return (int) $source_id . ':' . $type . ':' . (int) $node_id;
    }

    /**
     * @param string $cache_key
     * @return bool
     */
    protected function isSyncDue($cache_key)
    {
        $map = $this->getSyncTimeMap();
        $last = (int) ifset($map[$cache_key], 0);
        return time() - $last >= $this->getSyncTtl();
    }

    /**
     * @param string $cache_key
     */
    protected function markSynced($cache_key)
    {
        $map = $this->getSyncTimeMap();
        $map[$cache_key] = time();
        $this->setSyncTimeMap($map);
    }

    /**
     * @return array
     */
    protected function getSyncTimeMap()
    {
        $cache = new waSerializeCache(self::CACHE_ID, 86400, 'files');
        $map = $cache->get();
        return is_array($map) ? $map : array();
    }

    /**
     * @param array $map
     */
    protected function setSyncTimeMap(array $map)
    {
        $cache = new waSerializeCache(self::CACHE_ID, 86400, 'files');
        $cache->set($map);
    }

    /**
     * @param filesSource $source
     * @param array $context
     */
    protected function runSync($source, array $context)
    {
        $source->syncData(array(
            'context' => $context,
        ));
        $source->pauseSync();
        $sync = new filesSourceSync($source);
        $sync->process();
        $source->unpauseSync();
    }
}
