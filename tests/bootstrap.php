<?php

/**
 * Bootstrap for files/s3 plugin PHPUnit suite (standalone from tests/wa-apps/files/).
 * Framework root: wa-apps/files/plugins/s3/tests -> 5 levels up.
 *
 * Unit tests can run after framework init + plugin class includes even when MySQL
 * is unavailable. Integration tests (@group long_tests) require wa('files') + DB.
 */
$framework_root = dirname(__DIR__, 5);
require_once $framework_root . '/tests/init.php';

// PHPUnit 6+ removed PHPUnit_Framework_TestCase; shim stays inside s3 bootstrap only.
if (class_exists(\PHPUnit\Framework\TestCase::class, false) && !class_exists('PHPUnit_Framework_TestCase', false)) {
    class_alias(\PHPUnit\Framework\TestCase::class, 'PHPUnit_Framework_TestCase');
}

$GLOBALS['files_s3_files_app_ready'] = false;
try {
    wa('files');
    $GLOBALS['files_s3_files_app_ready'] = true;
} catch (Throwable $e) {
    $GLOBALS['files_s3_files_app_error'] = $e->getMessage();
}

$plugin_lib = dirname(__DIR__) . '/lib';

/**
 * Load a plugin class file if the class is not already defined.
 *
 * @param string $relative
 */
$files_s3_require = function ($relative) use ($plugin_lib) {
    $path = $plugin_lib . '/' . ltrim($relative, '/');
    if (!is_file($path)) {
        return;
    }
    require_once $path;
};

// Pure / WA-core-only classes (safe without files app).
$files_s3_require('classes/filesS3Xml.class.php');
$files_s3_require('classes/filesS3SignatureV4.class.php');
$files_s3_require('classes/filesS3ChunkedDecoder.class.php');

if ($GLOBALS['files_s3_files_app_ready']) {
    try {
        wa('files')->getPlugin('s3');
    } catch (Throwable $e) {
        // fall through to manual requires
    }
    $files_s3_require('filesS3Plugin.class.php');
    $files_s3_require('classes/filesS3AuthUser.class.php');
    $files_s3_require('classes/filesS3Auth.class.php');
    $files_s3_require('classes/filesS3File.model.php');
    $files_s3_require('classes/filesS3Multipart.model.php');
    $files_s3_require('classes/filesS3ListSync.class.php');
    $files_s3_require('classes/filesS3Backend.class.php');
    $files_s3_require('classes/filesS3Server.class.php');
} else {
    // Without files app, skip model subclasses that extend filesFileModel.
    $files_s3_require('filesS3Plugin.class.php');
    $files_s3_require('classes/filesS3AuthUser.class.php');
    $files_s3_require('classes/filesS3Auth.class.php');
    $files_s3_require('classes/filesS3ListSync.class.php');
    $files_s3_require('classes/filesS3Backend.class.php');
    $files_s3_require('classes/filesS3Server.class.php');
}

require_once __DIR__ . '/php/FilesS3TestCase.php';
require_once __DIR__ . '/php/FilesS3RequestHelper.php';
require_once __DIR__ . '/php/FilesS3SigV4RequestBuilder.php';
require_once __DIR__ . '/php/FilesS3SignatureV4TestDouble.php';
require_once __DIR__ . '/php/FilesS3AuthUserTestDouble.php';
require_once __DIR__ . '/php/FilesS3BackendTestDouble.php';
require_once __DIR__ . '/php/FilesS3ListSyncTestDouble.php';
require_once __DIR__ . '/php/FilesS3ServerTestDouble.php';
require_once __DIR__ . '/php/FilesS3IntegrationTestCase.php';
