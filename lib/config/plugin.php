<?php
return array(
    'name'        => /*_wp*/('S3 Server'),
    'description' => /*_wp*/('Provides S3-compatible protocol access to Files app storages'),
    'icon'        => 'img/logo.png',
    'img'         => 'img/logo.png',
    'version'     => '1.0.0',
    'vendor'      => '1078318',
    'handlers'    => array(
        'files_frontend_request' => 'frontendRequest',
        'backend_sidebar'        => 'backendSidebar',
    ),
);
