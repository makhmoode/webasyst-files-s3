<?php

return array(
    'files_s3_multipart' => array(
        'upload_id'       => array('varchar', 64, 'null' => 0),
        'contact_id'      => array('int', 11, 'null' => 0),
        'storage_id'      => array('int', 11, 'null' => 0),
        'parent_id'       => array('int', 11, 'null' => 0, 'default' => '0'),
        'filename'        => array('varchar', 255, 'null' => 0),
        'create_datetime' => array('datetime', 'null' => 0),
        ':keys' => array(
            'PRIMARY' => 'upload_id',
        ),
    ),
    'files_s3_multipart_part' => array(
        'id'              => array('int', 11, 'null' => 0, 'autoincrement' => 1),
        'upload_id'       => array('varchar', 64, 'null' => 0),
        'part_number'     => array('int', 11, 'null' => 0),
        'size'            => array('int', 11, 'null' => 0, 'default' => '0'),
        'etag'            => array('varchar', 64, 'null' => 0),
        'create_datetime' => array('datetime', 'null' => 0),
        ':keys' => array(
            'PRIMARY' => 'id',
            'upload_part' => array('upload_id', 'part_number', 'unique' => 1),
        ),
    ),
);
