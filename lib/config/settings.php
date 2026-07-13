<?php

return array(
    'top_block' => array(
        'control_type' => waHtmlControl::CUSTOM . ' ' . 'filesS3Plugin::getTopBlockHtml',
    ),
    'enable' => array(
        'title'        => _wp('Enable S3'),
        'control_type' => waHtmlControl::CUSTOM . ' ' . 'filesS3Plugin::getEnableHtml',
    ),
    'settlement' => array(
        'title'        => _wp('Server URL'),
        'control_type' => waHtmlControl::CUSTOM . ' ' . 'filesS3Plugin::getSettlementHtml',
    ),
    'region' => array(
        'title'        => _wp('AWS region'),
        'value'        => 'us-east-1',
        'description'  => _wp('Region string for S3 client configuration and Signature V4 (e.g. us-east-1).'),
        'control_type' => waHtmlControl::INPUT,
    ),
    'list_sync_ttl' => array(
        'title'        => _wp('On-demand source sync TTL'),
        'value'        => '60',
        'description'  => _wp('When listing folders via S3, refresh on-demand external sources (Yandex Disk, S3 storage, etc.) if the last sync for that folder is older than this many seconds. Set to 0 to disable.'),
        'control_type' => waHtmlControl::INPUT,
    ),
);
