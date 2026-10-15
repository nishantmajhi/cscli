<?php

declare(strict_types=1);

use Cscli\Api\ApiClient;
use Cscli\Api\ApiException;
use Cscli\Api\ApiNotFoundException;
use Cscli\Application;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;

/** Catches what a call throws, so a test can assert on the exception itself. */
function thrown(callable $body): ?Throwable
{
    try {
        $body();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

test('each method asks for its route, relative to the base URL', function () {
    $mock = new Mock([Mock::text(''), Mock::text(''), Mock::text('')]);

    $mock->client->languages();
    $mock->client->listPrograms('php');
    $mock->client->viewProgram('factorial.php');

    assertSame(
        ['http://api.test/languages', 'http://api.test/list/php', 'http://api.test/view/factorial.php'],
        $mock->urls(),
        'the three routes'
    );
});

test('a base URL with a path keeps the path, with or without a trailing slash', function () {
    foreach (['http://host/cscli-api', 'http://host/cscli-api/', 'http://host/cscli-api//'] as $base) {
        $mock = new Mock([Mock::text('')], $base);
        $mock->client->languages();

        assertSame(['http://host/cscli-api/languages'], $mock->urls(), "base $base");
    }
});

test('names are percent-encoded, so c# and c++ reach the server intact', function () {
    $mock = new Mock([Mock::text(''), Mock::text(''), Mock::text('')]);

    $mock->client->listPrograms('c#');
    assertSame('/list/c%23', $mock->lastPath(), 'c#');

    $mock->client->listPrograms('c++');
    assertSame('/list/c%2B%2B', $mock->lastPath(), 'c++, which is not a space');

    $mock->client->viewProgram('a b?.php');
    assertSame('/view/a%20b%3F.php', $mock->lastPath(), 'space and question mark');
});

test('a successful body comes back exactly as sent', function () {
    $body = "\n  <?php\r\n echo 1;  \n\n";
    $mock = new Mock([Mock::text($body)]);

    assertSame($body, $mock->client->viewProgram('x.php'), 'not trimmed, not normalised');
});

test('404 is ApiNotFoundException carrying the server\'s own message', function () {
    $mock = new Mock([Mock::text("  'rust' is currently not supported.\nSee /languages.\n", 404)]);

    $e = thrown(fn () => $mock->client->listPrograms('rust'));

    assertSame(ApiNotFoundException::class, $e === null ? null : $e::class, 'the type');
    assertSame("'rust' is currently not supported.\nSee /languages.", $e?->getMessage(), 'trimmed body');
});

test('404 with an empty body still says what happened', function () {
    $mock = new Mock([Mock::text('', 404)]);

    assertSame('Resource not found.', thrown(fn () => $mock->client->languages())?->getMessage(), 'message');
});

test('other failures are ApiException, not the not-found type, and name the status', function () {
    foreach ([400, 413, 500, 503] as $status) {
        $mock = new Mock([Mock::text("Something broke.\n", $status)]);
        $e = thrown(fn () => $mock->client->languages());

        assertSame(ApiException::class, $e === null ? null : $e::class, "HTTP $status type");
        assertSame(
            "CSCLI API returned an unexpected error (HTTP $status): Something broke.",
            $e?->getMessage(),
            "HTTP $status message"
        );
    }
});

test('a connection failure is one plain sentence, not a Guzzle message', function () {
    $mock = new Mock([new ConnectException('cURL error 7: Failed to connect', new Request('GET', 'x'))]);
    $e = thrown(fn () => $mock->client->languages());

    assertSame(ApiException::class, $e === null ? null : $e::class, 'type');
    assertSame(
        'Could not reach the CSCLI API. Is the server running and is the URL correct?',
        $e?->getMessage(),
        'message'
    );
    assertSame(ConnectException::class, $e?->getPrevious() === null ? null : $e->getPrevious()::class, 'cause kept');
});

test('any other transport failure is still an ApiException', function () {
    $mock = new Mock([new TransferException('something odd')]);
    $e = thrown(fn () => $mock->client->languages());

    assertSame(ApiException::class, $e === null ? null : $e::class, 'type');
    assertSame('Request to the CSCLI API failed: something odd', $e?->getMessage(), 'message');
});

test('the base URL comes from CSCLI_API_URL, and an empty one means the default', function () {
    $saved = getenv('CSCLI_API_URL');

    try {
        putenv('CSCLI_API_URL=http://elsewhere:1234');
        assertSame('http://elsewhere:1234', Application::apiUrl(), 'set');

        putenv('CSCLI_API_URL=');
        assertSame('http://localhost:6300', Application::apiUrl(), 'empty');

        putenv('CSCLI_API_URL');
        assertSame('http://localhost:6300', Application::apiUrl(), 'unset');
    } finally {
        putenv($saved === false ? 'CSCLI_API_URL' : "CSCLI_API_URL=$saved");
    }
});

test('the default URL is the port cscli-api\'s README starts it on', function () {
    $api = Server::apiDirectory();

    if ($api === null) {
        skip('cscli-api is not beside this project');
        return;
    }

    assertContains(
        'php -S localhost:6300',
        (string) file_get_contents($api . '/README.md'),
        'cscli-api README'
    );
    assertSame('http://localhost:6300', Application::DEFAULT_API_URL, 'default');
});

test('nothing turns off TLS certificate checking', function () {
    $src = dirname(__DIR__) . '/src';
    $files = array_merge(glob("$src/*.php") ?: [], glob("$src/*/*.php") ?: []);

    assertTrue(count($files) >= 7, 'found the source files');

    foreach ($files as $file) {
        assertNotContains("'verify'", (string) file_get_contents($file), basename($file));
    }
});

test('a name that is only dots is sent encoded, so it is not resolved as a path step', function () {
    $mock = new Mock([Mock::text(''), Mock::text(''), Mock::text(''), Mock::text('')]);

    $mock->client->listPrograms('..');
    assertSame('/list/%2E%2E', $mock->lastPath(), '..');

    $mock->client->listPrograms('.');
    assertSame('/list/%2E', $mock->lastPath(), '.');

    $mock->client->viewProgram('..');
    assertSame('/view/%2E%2E', $mock->lastPath(), 'view ..');

    $mock->client->viewProgram('...');
    assertSame('/view/...', $mock->lastPath(), 'three dots are an ordinary name and are left alone');
});

test('viewProgram with a language asks the two-segment route, each segment encoded', function () {
    $mock = new Mock([Mock::text(''), Mock::text(''), Mock::text('')]);

    $mock->client->viewProgram('factorial.php', 'python');
    assertSame('/view/python/factorial.php', $mock->lastPath(), 'plain');

    $mock->client->viewProgram('factorial.cpp', 'c++');
    assertSame('/view/c%2B%2B/factorial.cpp', $mock->lastPath(), 'c++');

    $mock->client->viewProgram('x', '..');
    assertSame('/view/%2E%2E/x', $mock->lastPath(), 'a dot-only language is encoded too');
});

test('an empty language is the same as none, not an empty path segment', function () {
    $mock = new Mock([Mock::text('')]);
    $mock->client->viewProgram('factorial.php', '');

    assertSame('/view/factorial.php', $mock->lastPath(), 'route');
});
