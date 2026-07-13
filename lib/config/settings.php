<?php

$is_russian_locale = wa()->getLocale() === 'ru_RU';
$region_placeholder = $is_russian_locale ? 'ru-central1' : 'us-east-1';

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
        'description'  => _wp('Only a root settlement of the Files app can be used as S3 endpoint.'),
        'control_type' => waHtmlControl::CUSTOM . ' ' . 'filesS3Plugin::getSettlementHtml',
    ),
    'region' => array(
        'title'        => _wp('AWS region'),
        'value'        => 'server-1',
        'placeholder'  => $region_placeholder,
        'description'  => _wp('Region string for S3 client configuration and Signature V4 (e.g. us-east-1).'),
        'control_type' => waHtmlControl::INPUT,
    ),
    'list_sync_ttl' => array(
        'title'        => _wp('On-demand source sync TTL'),
        'value'        => '60',
        'description'  => _wp('When listing folders via S3, refresh on-demand external sources (Yandex Disk, S3 storage, etc.) if the last sync for that folder is older than this many seconds. Set to 0 to disable.'),
        'control_type' => waHtmlControl::INPUT,
    ),
    'users_secrets_block' => array(
        'control_type' => waHtmlControl::CUSTOM . ' ' . 'filesS3Plugin::getUsersSecretsBlockHtml',
    ),
);
