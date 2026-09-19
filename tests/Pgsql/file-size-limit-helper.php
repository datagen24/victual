<?php

// One boot of the configuration, the way app.php and bin/victual-migrate do it, then the
// validator, then the effective upload limit printed as JSON. For
// tests/Pgsql/FileSizeLimitTest.php, which runs this under different php.ini values and
// different SAPIs (php and php-cgi) and reads what reached error_log.
//
//   php -d upload_max_filesize=1M -d error_log=<file> file-size-limit-helper.php
//
// Output: {"sapi": "<PHP_SAPI>", "effective_mb": <number>}. Needs VICTUAL_DATAPATH in the
// environment, pointing at a directory holding a config.php; no database is opened.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));
define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH'));
define('VICTUAL_IS_EMBEDDED_INSTALL', false);
require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';
require_once VICTUAL_DATAPATH . '/config.php';
require_once VICTUAL_ROOT_PATH . '/config-dist.php';

use Victual\Helpers\ConfigurationValidator;
use Victual\Services\Storage\FileSizeLimit;

(new ConfigurationValidator())->validateConfig();

echo json_encode(['sapi' => PHP_SAPI, 'effective_mb' => FileSizeLimit::EffectiveMaxMegabytes()]);
