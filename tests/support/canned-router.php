<?php

/**
 * Router for `php -S` that answers from a table instead of a source tree.
 *
 * For responses the real API cannot be made to give on this machine: a file
 * name that is not valid UTF-8, or one containing < and >, neither of which
 * NTFS will store. CSCLI_CANNED names a JSON file mapping a path to
 * [status, base64 body]; base64 because JSON cannot carry invalid UTF-8.
 */

declare(strict_types=1);

$routes = json_decode((string) file_get_contents((string) getenv('CSCLI_CANNED')), true);
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

[$status, $body] = $routes[$path] ?? [404, base64_encode("Nothing canned for $path\n")];

http_response_code($status);
header('Content-Type: text/plain; charset=utf-8');
echo base64_decode($body);
