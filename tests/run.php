<?php

/**
 * Test runner.
 *
 * Usage:  php tests/run.php  [filter]
 *
 * Loads every *Test.php in this directory and reports the result. An optional
 * argument limits the run to files whose name contains that substring.
 *
 * Needs `composer install` first, because the suite drives the real Symfony
 * Console and Guzzle. The end-to-end tests also need the sibling cscli-api
 * (../cscli-api, or wherever CSCLI_API_DIR points) and skip without it.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies not installed. Run 'composer install' first.\n");
    exit(1);
}

require $autoload;
require_once __DIR__ . '/Harness.php';

foreach (glob(__DIR__ . '/support/*.php') ?: [] as $support) {
    if (!str_ends_with($support, '-router.php')) {
        require_once $support;
    }
}

$filter = $argv[1] ?? '';
$files  = glob(__DIR__ . DIRECTORY_SEPARATOR . '*Test.php') ?: [];

sort($files);

foreach ($files as $file) {
    if ($filter !== '' && !str_contains(basename($file), $filter)) {
        continue;
    }

    echo basename($file, '.php') . PHP_EOL;

    require_once $file;
}

exit(Harness::summary());
