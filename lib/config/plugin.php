<?php
return array(
    'name'        => /*_wp*/('S3 server'),
    'description' => /*_wp*/('Provides S3-compatible protocol access to Files app storages'),
    'img'         => 'img/logo.png',
    'version'     => '1.0.0',
    'vendor'      => 'webasyst',
    'handlers'    => array(
        'files_frontend_request' => 'frontendRequest',
        'backend_sidebar'        => 'backendSidebar',
    ),
);
