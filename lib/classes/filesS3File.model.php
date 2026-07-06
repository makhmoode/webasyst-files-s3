<?php

class filesS3FileModel extends filesFileModel
{
    public function __construct($type = null, $writable = false)
    {
        parent::__construct($type, $writable);
        $this->setCheckInSync(true);
    }

    public function getAllByStorageId($id)
    {
        return $this->query(
            "SELECT id, name, size, type, update_datetime, create_datetime, source_id, storage_id, parent_id
             FROM {$this->table}
             WHERE storage_id = ? AND parent_id = 0
             ORDER BY type DESC, name ASC",
            $id
        )->fetchAll();
    }

    public function getAllByParentId($id)
    {
        return $this->query(
            "SELECT id, name, size, type, update_datetime, create_datetime, source_id, storage_id, parent_id
             FROM {$this->table}
             WHERE parent_id = ?
             ORDER BY type DESC, name ASC",
            $id
        )->fetchAll();
    }
}
