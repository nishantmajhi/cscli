<?php

/**
 * Router for `php -S`: the real cscli-api route table over a fixture tree.
 *
 * This is the sibling project's own dispatch(), not a stand-in for it, so the
 * end-to-end tests check the client against the server it is written for.
 * Only the source root differs from cscli-api's index.php.
 */

declare(strict_types=1);

require getenv('CSCLI_API_DIR') . '/utils/dispatch.php';

dispatch(
    new Router($_SERVER['REQUEST_URI'] ?? '/'),
    new Catalog((string) getenv('CSCLI_FIXTURE_ROOT')),
    wantsJson($_SERVER['HTTP_ACCEPT'] ?? ''),
    $_SERVER['HTTP_IF_NONE_MATCH'] ?? null
)->send();
