<?php

class filesS3MultipartModel extends waModel
{
    protected $table = 'files_s3_multipart';

    /**
     * @var string
     */
    protected $id = 'upload_id';

    /**
     * @param int $contact_id
     * @param int $storage_id
     * @param int $parent_id
     * @param string $filename
     * @return string|false
     */
    public function createUpload($contact_id, $storage_id, $parent_id, $filename)
    {
        $upload_id = bin2hex(random_bytes(16));
        $ok = $this->insert(array(
            'upload_id'       => $upload_id,
            'contact_id'      => (int) $contact_id,
            'storage_id'      => (int) $storage_id,
            'parent_id'       => (int) $parent_id,
            'filename'        => $filename,
            'create_datetime' => date('Y-m-d H:i:s'),
        ));
        if (!$ok) {
            return false;
        }
        if (!$this->getUpload($upload_id)) {
            return false;
        }
        return $upload_id;
    }

    /**
     * @param string $upload_id
     * @param int|null $contact_id
     * @return array|null
     */
    public function getUpload($upload_id, $contact_id = null)
    {
        $data = array('upload_id' => $upload_id);
        if ($contact_id !== null) {
            $data['contact_id'] = (int) $contact_id;
        }
        return $this->getByField($data);
    }

    public function deleteUpload($upload_id)
    {
        $this->deleteByField('upload_id', $upload_id);
        $pm = new filesS3MultipartPartModel();
        $pm->deleteByField('upload_id', $upload_id);
        $this->deletePartFiles($upload_id);
    }

    public function getPartsDir($upload_id)
    {
        return wa()->getDataPath('plugins/s3/multipart/' . $upload_id . '/', true, 'files');
    }

    public function deletePartFiles($upload_id)
    {
        $dir = $this->getPartsDir($upload_id);
        if (is_dir($dir)) {
            waFiles::delete($dir);
        }
    }

    public function getPartPath($upload_id, $part_number)
    {
        return $this->getPartsDir($upload_id) . (int) $part_number;
    }
}

class filesS3MultipartPartModel extends waModel
{
    protected $table = 'files_s3_multipart_part';

    public function savePart($upload_id, $part_number, $size, $etag)
    {
        $this->replace(array(
            'upload_id'       => $upload_id,
            'part_number'     => (int) $part_number,
            'size'            => (int) $size,
            'etag'            => $etag,
            'create_datetime' => date('Y-m-d H:i:s'),
        ));
    }

    public function getParts($upload_id)
    {
        return $this->select('*')
            ->where('upload_id = ?', $upload_id)
            ->order('part_number')
            ->fetchAll();
    }
}
