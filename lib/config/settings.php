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
);
