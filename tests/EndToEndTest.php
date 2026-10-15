<?php

declare(strict_types=1);

/**
 * bin/cscli as a separate process, against cscli-api's own route table
 * serving the fixture tree over a real socket.
 *
 * This is the only place the client and the server it was written for meet,
 * so the contract between them - route shapes, encoding, status codes, body
 * formats - is tested here and nowhere else. It skips if cscli-api is not
 * beside this project, or is a version from before its routes were a
 * function that could be loaded.
 */

/** The API's URL, or null after reporting a skip. */
function liveApi(): ?string
{
    $url = Server::api();

    if ($url === null) {
        skip(Server::apiDirectory() === null
            ? 'cscli-api with utils/dispatch.php is not beside this project (set CSCLI_API_DIR)'
            : 'could not start php -S');
    }

    return $url;
}

test('the rebuilt original is the first commit and runs', function () {
    if (($api = liveApi()) === null) {
        return;
    }

    $original = Cli::original();

    if ($original === null) {
        skip('git is unavailable');
        return;
    }

    $result = $original->run(['languages', '--json'], $api);

    assertSame(0, $result->exit, 'exit');
    assertSame(
        ['languages' => ['c#', 'c++', 'empty', 'php', 'python']],
        json_decode($result->stdout, true),
        'the original lists the fixture languages'
    );
});

test('languages, programs and view work end to end', function () {
    if (($api = liveApi()) === null) {
        return;
    }

    $cli = Cli::current();

    $languages = $cli->run(['languages', '--json'], $api);
    assertSame(['languages' => ['c#', 'c++', 'empty', 'php', 'python']], json_decode($languages->stdout, true), 'languages');

    $programs = $cli->run(['programs', 'php', '--json'], $api);
    assertSame(['language' => 'php', 'programs' => ['factorial.php', 'markup.php']], json_decode($programs->stdout, true), 'programs');

    $view = $cli->run(['view', 'hello.py'], $api);
    assertSame(0, $view->exit, 'view exit');
    assertSame("print('hello')\n", $view->stdout, 'view');
});

test('c# and c++ can be listed, which needed the API to decode its segments', function () {
    if (($api = liveApi()) === null) {
        return;
    }

    foreach (['c#' => 'factorial.cs', 'c++' => 'factorial.cpp'] as $language => $program) {
        $result = Cli::current()->run(['programs', $language, '--json'], $api);

        assertSame(0, $result->exit, "$language exit");
        assertSame(['language' => $language, 'programs' => [$program]], json_decode($result->stdout, true), $language);
    }
});

test('an unknown language and an unknown program are errors with exit 1', function () {
    if (($api = liveApi()) === null) {
        return;
    }

    $language = Cli::current()->run(['programs', 'rust'], $api);
    assertSame(1, $language->exit, 'programs exit');
    assertContains("'rust' is currently not supported.", $language->errorText(), 'programs stderr');
    assertSame('', $language->stdout, 'programs stdout');

    $program = Cli::current()->run(['view', 'nope.php'], $api);
    assertSame(1, $program->exit, 'view exit');
    assertContains("Program 'nope.php' not found.", $program->errorText(), 'view stderr');
});

test('with no server to reach, the message says so and the exit is 1', function () {
    $result = Cli::current()->run(['languages'], 'http://cscli.invalid');

    assertSame(1, $result->exit, 'exit');
    assertContains('Could not reach the CSCLI API.', $result->errorText(), 'stderr');
    assertNotContains('Guzzle', $result->stderr, 'no library internals');
});

regression('view writes the source byte for byte, even when it looks like console markup', function (Cli $cli) {
    if (($api = liveApi()) === null) {
        return;
    }

    foreach ([[], ['--ansi'], ['--no-ansi']] as $flags) {
        $result = $cli->run(array_merge(['view', 'markup.php'], $flags), $api);
        $label = $cli->label . ' ' . ($flags[0] ?? 'default');

        assertSame(0, $result->exit, "$label exit");
        assertSame(Fixture::MARKUP_SOURCE, $result->stdout, "$label stdout");
    }
});

regression('view --output writes the same bytes view prints', function (Cli $cli) {
    if (($api = liveApi()) === null) {
        return;
    }

    $dir = Fixture::directory('out');
    $printed = $cli->run(['view', 'markup.php'], $api, $dir);
    $saved = $cli->run(['view', 'markup.php', '-o', 'saved.php'], $api, $dir);

    assertSame(0, $saved->exit, "{$cli->label} exit");
    assertSame(Fixture::MARKUP_SOURCE, (string) file_get_contents($dir . '/saved.php'), "{$cli->label} file");
    assertSame(Fixture::MARKUP_SOURCE, $printed->stdout, "{$cli->label} stdout agrees with the file");
});

regression('-q prints the source, the JSON and the errors', function (Cli $cli) {
    if (($api = liveApi()) === null) {
        return;
    }

    $view = $cli->run(['view', 'hello.py', '-q'], $api);
    assertSame("print('hello')\n", $view->stdout, "{$cli->label} view -q");

    $json = $cli->run(['programs', 'python', '--json', '-q'], $api);
    assertSame(['language' => 'python', 'programs' => ['factorial.php', 'hello.py']], json_decode($json->stdout, true), "{$cli->label} --json -q");

    $missing = $cli->run(['view', 'nope.php', '-q'], $api);
    assertSame(1, $missing->exit, "{$cli->label} error exit");
    assertContains("Program 'nope.php' not found.", $missing->errorText(), "{$cli->label} error -q");
});

regression('`..` and `.` are names the server does not have, not the parent route', function (Cli $cli) {
    if (($api = liveApi()) === null) {
        return;
    }

    // Before: `programs ..` resolved to "/" and listed the API's welcome
    // text as programs; `view ..` printed it as source. Both exited 0.
    $up = $cli->run(['programs', '..'], $api);
    assertSame(1, $up->exit, "{$cli->label} programs .. exit");
    assertSame('', $up->stdout, "{$cli->label} programs .. stdout");
    assertContains("'..' is currently not supported.", $up->errorText(), "{$cli->label} programs ..");

    $view = $cli->run(['view', '..'], $api);
    assertSame(1, $view->exit, "{$cli->label} view .. exit");
    assertSame('', $view->stdout, "{$cli->label} view .. stdout");
    assertContains("Program '..' not found.", $view->errorText(), "{$cli->label} view ..");

    // Before: "/list/" - a 400 about a missing language the user did give.
    $here = $cli->run(['programs', '.'], $api);
    assertSame(1, $here->exit, "{$cli->label} programs . exit");
    assertContains("'.' is currently not supported.", $here->errorText(), "{$cli->label} programs .");
});

regression('odd names from the server come out as the server sent them', function (Cli $cli) {
    $api = Server::canned([
        '/list/php' => [200, "Programs in php:\n- caf\xE9.php\n- <info>x</info>.php\n"],
        '/languages' => [200, "Supported Languages:\n- a\<b\n- <comment>c</comment>\n"],
    ]);

    if ($api === null) {
        skip('could not start php -S');
        return;
    }

    $json = $cli->run(['programs', 'php', '--json'], $api);
    assertSame(0, $json->exit, "{$cli->label} --json exit");
    assertSame(
        ['language' => 'php', 'programs' => ["caf\u{FFFD}.php", '<info>x</info>.php']],
        json_decode($json->stdout, true),
        "{$cli->label} --json"
    );

    $list = $cli->run(['languages'], $api);
    assertContains(' * a\<b', $list->stdout, "{$cli->label} backslash");
    assertContains(' * <comment>c</comment>', $list->stdout, "{$cli->label} tag");
});

regression('view --language reaches a file that another language also has', function (Cli $cli) {
    if (($api = liveApi()) === null) {
        return;
    }

    // php/factorial.php and python/factorial.php both exist. Without a
    // language the server answers from the first in sorted order, so the
    // python one could not be reached from the client at all.
    $default = $cli->run(['view', 'factorial.php'], $api);
    assertSame("<?php\necho 'php';\n", $default->stdout, "{$cli->label} without a language");

    $python = $cli->run(['view', 'factorial.php', '--language', 'python'], $api);
    assertSame(0, $python->exit, "{$cli->label} --language exit");
    assertSame("print('python')\n", $python->stdout, "{$cli->label} --language python");

    $cpp = $cli->run(['view', 'factorial.cpp', '-l', 'c++'], $api);
    assertSame("int main() {}\n", $cpp->stdout, "{$cli->label} -l c++");

    $wrong = $cli->run(['view', 'hello.py', '-l', 'php'], $api);
    assertSame(1, $wrong->exit, "{$cli->label} wrong language exit");
    assertContains("Program 'hello.py' not found.", $wrong->errorText(), "{$cli->label} wrong language");

    $unknown = $cli->run(['view', 'factorial.php', '-l', 'rust'], $api);
    assertSame(1, $unknown->exit, "{$cli->label} unknown language exit");
    assertContains("'rust' is currently not supported.", $unknown->errorText(), "{$cli->label} unknown language");
});
