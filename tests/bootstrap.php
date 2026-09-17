<?php

// PHPUnit's bootstrap. Kept to the one thing every process needs before anything else
// runs - the autoloader - and nothing that only some scenarios want.
//
// Application configuration (config.php, config-dist.php) is deliberately NOT loaded
// here: a handful of rbac-tests.php's ported scenarios (the default-role and
// own-picture cases) need a VICTUAL_* constant defined before config-dist.php first
// runs, and PHP constants cannot be redefined once set. Those scenarios run in their
// own process - see tests/Pgsql/rbac-subprocess-helper.php - so this file loading
// config-dist.php eagerly would fix VICTUAL_DEFAULT_ROLES etc. before that process gets
// a chance to override it. Victual\Tests\Support\PgsqlSchemaTestCase::Boot() does the
// real boot, once per process, lazily.

define('VICTUAL_ROOT_PATH', dirname(__DIR__));

require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';

// views/layout/default.blade.php reads $_SERVER['REQUEST_URI'] for the manifest link,
// which a CLI process does not have - the same shim
// .devtools/pgsql/price-visibility-tests.php already applies for the same reason, so a
// test that renders the real layout gets it instead of a PHP warning.
$_SERVER['REQUEST_URI'] ??= '/';
