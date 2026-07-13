<?php

class FilesS3ListSyncTest extends FilesS3TestCase
{
    public function testGetSyncTtlDefaultsAndParsesSettings()
    {
        $sync = new FilesS3ListSyncTestDouble();
        $this->assertSame(60, $sync->exposeGetSyncTtl());

        $sync = new FilesS3ListSyncTestDouble(array('list_sync_ttl' => '120'));
        $this->assertSame(120, $sync->exposeGetSyncTtl());

        $sync = new FilesS3ListSyncTestDouble(array('list_sync_ttl' => '0'));
        $this->assertSame(0, $sync->exposeGetSyncTtl());
    }

    public function testBuildCacheKey()
    {
        $sync = new FilesS3ListSyncTestDouble();
        $this->assertSame('7:folder:42', $sync->exposeBuildCacheKey(7, 'folder', 42));
        $this->assertSame('3:storage:9', $sync->exposeBuildCacheKey(3, 'storage', 9));
    }

    public function testIsSyncDueRespectsTtl()
    {
        $sync = new FilesS3ListSyncTestDouble(array('list_sync_ttl' => 60));
        $key = '1:folder:10';

        $this->assertTrue($sync->exposeIsSyncDue($key));

        $sync->seedSyncTime($key, time() - 30);
        $this->assertFalse($sync->exposeIsSyncDue($key));

        $sync->seedSyncTime($key, time() - 61);
        $this->assertTrue($sync->exposeIsSyncDue($key));
    }

    public function testSyncIfNeededSkipsWhenTtlDisabled()
    {
        $sync = new FilesS3ListSyncTestDouble(array('list_sync_ttl' => 0));
        $sync->syncIfNeeded(array(
            'id'         => 1,
            'type'       => 'folder',
            'source_id'  => 5,
        ));

        $this->assertSame(0, $sync->getSyncCount());
        $this->assertSame(array(), $sync->sync_calls);
    }

    public function testSyncDescriptorSkipsWhenWithinTtl()
    {
        $sync = new FilesS3ListSyncTestDouble(array('list_sync_ttl' => 300));
        $sync->seedSyncTime('5:folder:1', time());

        $sync->exposeSyncDescriptor(array(
            'source_id' => 5,
            'cache_key' => '5:folder:1',
            'context'   => array('folder' => array('id' => 1, 'type' => 'folder')),
        ));

        $this->assertSame(0, $sync->getSyncCount());
        $this->assertSame(array(), $sync->sync_calls);
    }

    public function testSyncDescriptorRunsWhenExpired()
    {
        $sync = new FilesS3ListSyncTestDouble(array('list_sync_ttl' => 60));
        $sync->seedSyncTime('5:folder:1', time() - 120);

        $sync->exposeSyncDescriptor(array(
            'source_id' => 5,
            'cache_key' => '5:folder:1',
            'context'   => array('folder' => array('id' => 1, 'type' => 'folder')),
        ));

        $this->assertSame(1, $sync->getSyncCount());
        $this->assertCount(1, $sync->sync_calls);
        $this->assertSame(5, $sync->sync_calls[0]['source_id']);
        $this->assertFalse($sync->exposeIsSyncDue('5:folder:1'));
    }
}
