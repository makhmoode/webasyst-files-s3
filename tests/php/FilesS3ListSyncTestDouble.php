<?php

/**
 * Test double for filesS3ListSync with in-memory TTL map and sync hook capture.
 */
class FilesS3ListSyncTestDouble extends filesS3ListSync
{
    /**
     * @var array
     */
    public $sync_time_map = array();

    /**
     * @var array
     */
    public $sync_calls = array();

    /**
     * @var bool
     */
    public $run_sync_result = true;

    /**
     * @param array $settings
     */
    public function __construct(array $settings = array())
    {
        parent::__construct($settings);
    }

    /**
     * @return int
     */
    public function exposeGetSyncTtl()
    {
        return $this->getSyncTtl();
    }

    /**
     * @param array $parent
     * @return array|null
     */
    public function exposeBuildDescriptor($parent)
    {
        return $this->buildDescriptor($parent);
    }

    /**
     * @param int $source_id
     * @param string $type
     * @param int $node_id
     * @return string
     */
    public function exposeBuildCacheKey($source_id, $type, $node_id)
    {
        return $this->buildCacheKey($source_id, $type, $node_id);
    }

    /**
     * @param string $cache_key
     * @return bool
     */
    public function exposeIsSyncDue($cache_key)
    {
        return $this->isSyncDue($cache_key);
    }

    /**
     * @param string $cache_key
     * @param int $timestamp
     */
    public function seedSyncTime($cache_key, $timestamp)
    {
        $this->sync_time_map[$cache_key] = $timestamp;
    }

    /**
     * @return array
     */
    protected function getSyncTimeMap()
    {
        return $this->sync_time_map;
    }

    /**
     * @param array $map
     */
    protected function setSyncTimeMap(array $map)
    {
        $this->sync_time_map = $map;
    }

    /**
     * @param array $descriptor
     */
    public function exposeSyncDescriptor(array $descriptor)
    {
        $cache_key = $descriptor['cache_key'];
        if (!$this->isSyncDue($cache_key)) {
            return;
        }

        try {
            $this->runSync(null, $descriptor['context'], $descriptor['source_id']);
            $this->markSynced($cache_key);
            $this->sync_count++;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * @param filesSource|null $source
     * @param array $context
     * @param int|null $source_id
     */
    protected function runSync($source, array $context, $source_id = null)
    {
        if (!$this->run_sync_result) {
            throw new Exception('sync failed');
        }
        $this->sync_calls[] = array(
            'source_id' => $source_id !== null ? $source_id : ($source ? $source->getId() : 0),
            'context'   => $context,
        );
    }
}
