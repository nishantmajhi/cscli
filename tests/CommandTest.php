<?php

declare(strict_types=1);

/**
 * The three commands, run through the real Application with the API mocked.
 */

const LANGUAGES_BODY = "Supported Languages:\n- c\n- c#\n- c++\n- php\n";
const PROGRAMS_BODY = "Programs in php:\n- factorial.php\n- hello_world.php\n";

// languages

test('languages lists what the server lists, without its heading', function () {
    $mock = new Mock([Mock::text(LANGUAGES_BODY)]);
    $tester = $mock->run(['command' => 'languages']);

    assertSame(0, $tester->getStatusCode(), 'exit');
    assertSame("\nSupported Languages\n-------------------\n\n * c\n * c#\n * c++\n * php\n\n", $tester->getDisplay(true), 'stdout');
    assertSame('', $tester->getErrorOutput(), 'stderr');
});

test('languages --json is the parsed list', function () {
    $mock = new Mock([Mock::text(LANGUAGES_BODY)]);
    $tester = $mock->run(['command' => 'languages', '--json' => true]);

    assertSame(0, $tester->getStatusCode(), 'exit');
    assertSame(['languages' => ['c', 'c#', 'c++', 'php']], json_decode($tester->getDisplay(), true), 'decoded');
});

test('languages reports an API failure on stderr and exits 1', function () {
    $mock = new Mock([Mock::text('boom', 500)]);
    $tester = $mock->run(['command' => 'languages']);

    assertSame(1, $tester->getStatusCode(), 'exit');
    assertSame('', $tester->getDisplay(), 'nothing on stdout');
    assertContains('[ERROR] CSCLI API returned an unexpected error (HTTP 500): boom', errorText($tester), 'stderr');
});

// programs

test('programs asks for the language given and lists the result', function () {
    $mock = new Mock([Mock::text(PROGRAMS_BODY)]);
    $tester = $mock->run(['command' => 'programs', 'language' => 'php']);

    assertSame('/list/php', $mock->lastPath(), 'route');
    assertSame(0, $tester->getStatusCode(), 'exit');
    assertSame("\nPrograms in php\n---------------\n\n * factorial.php\n * hello_world.php\n\n", $tester->getDisplay(true), 'stdout');
});

test('programs --json names the language and the programs', function () {
    $mock = new Mock([Mock::text(PROGRAMS_BODY)]);
    $tester = $mock->run(['command' => 'programs', 'language' => 'php', '--json' => true]);

    assertSame(
        ['language' => 'php', 'programs' => ['factorial.php', 'hello_world.php']],
        json_decode($tester->getDisplay(), true),
        'decoded'
    );
});

test('programs for an unknown language says so and points at `cscli languages`', function () {
    $mock = new Mock([Mock::text("'rust' is currently not supported.\n", 404)]);
    $tester = $mock->run(['command' => 'programs', 'language' => 'rust']);

    assertSame(1, $tester->getStatusCode(), 'exit');
    assertContains("[ERROR] 'rust' is currently not supported.", errorText($tester), 'error');
    assertContains('Run "cscli languages" to see the supported language names.', errorText($tester), 'hint');
});

test('programs for a language with nothing in it warns and still succeeds', function () {
    $mock = new Mock([Mock::text("Programs in empty: (none found)\n")]);
    $tester = $mock->run(['command' => 'programs', 'language' => 'empty']);

    assertSame(0, $tester->getStatusCode(), 'exit');
    assertContains("[WARNING] No programs found for 'empty'.", trim((string) preg_replace('/\s+/', ' ', $tester->getDisplay())), 'warning');
});

// view

test('view prints the source exactly, with no newline added', function () {
    $mock = new Mock([Mock::text("<?php\necho 1;")]);
    $tester = $mock->run(['command' => 'view', 'program' => 'factorial.php']);

    assertSame('/view/factorial.php', $mock->lastPath(), 'route');
    assertSame(0, $tester->getStatusCode(), 'exit');
    assertSame("<?php\necho 1;", $tester->getDisplay(), 'stdout');
});

test('view of a missing program reports the server\'s message and exits 1', function () {
    $mock = new Mock([Mock::text("Program 'nope.php' not found.\n", 404)]);
    $tester = $mock->run(['command' => 'view', 'program' => 'nope.php']);

    assertSame(1, $tester->getStatusCode(), 'exit');
    assertSame('', $tester->getDisplay(), 'stdout');
    assertContains("[ERROR] Program 'nope.php' not found.", errorText($tester), 'stderr');
});

test('view --output writes the file and says where', function () {
    $dir = Fixture::directory('view');
    $target = $dir . DIRECTORY_SEPARATOR . 'out.php';

    $mock = new Mock([Mock::text("<?php\necho 1;\n")]);
    $tester = $mock->run(['command' => 'view', 'program' => 'x.php', '--output' => $target]);

    assertSame(0, $tester->getStatusCode(), 'exit');
    assertSame("<?php\necho 1;\n", (string) file_get_contents($target), 'file');
    assertContains('[OK] Saved to', trim((string) preg_replace('/\s+/', ' ', $tester->getDisplay())), 'confirmation');
});

test('view --output will not overwrite without asking, and -n means no', function () {
    $target = Fixture::directory('view') . DIRECTORY_SEPARATOR . 'out.php';
    file_put_contents($target, 'keep me');

    $mock = new Mock([Mock::text('new')]);
    $tester = $mock->run(['command' => 'view', 'program' => 'x.php', '-o' => $target, '-n' => true]);

    assertSame(1, $tester->getStatusCode(), 'exit');
    assertSame('keep me', (string) file_get_contents($target), 'file untouched');
    assertContains('Aborted.', $tester->getErrorOutput(), 'stderr');
});

test('view --output overwrites when the answer is yes', function () {
    $target = Fixture::directory('view') . DIRECTORY_SEPARATOR . 'out.php';
    file_put_contents($target, 'old');

    $mock = new Mock([Mock::text('new')]);
    $tester = $mock->run(['command' => 'view', 'program' => 'x.php', '-o' => $target], ['yes'], true);

    assertSame(0, $tester->getStatusCode(), 'exit');
    assertSame('new', (string) file_get_contents($target), 'file replaced');
});

test('view --output reports a file it cannot write', function () {
    $dir = Fixture::directory('view');
    $target = $dir . DIRECTORY_SEPARATOR . 'no-such-dir' . DIRECTORY_SEPARATOR . 'out.php';

    $mock = new Mock([Mock::text('x')]);
    $tester = $mock->run(['command' => 'view', 'program' => 'x.php', '-o' => $target]);

    assertSame(1, $tester->getStatusCode(), 'exit');
    assertContains('Could not write to', errorText($tester), 'stderr');
});

test('view does not run the source through the console formatter, coloured or not', function () {
    foreach ([false, true] as $decorated) {
        $mock = new Mock([Mock::text(Fixture::MARKUP_SOURCE)]);
        $tester = $mock->run(['command' => 'view', 'program' => 'markup.php'], [], false, $decorated);

        assertSame(Fixture::MARKUP_SOURCE, $tester->getDisplay(), $decorated ? 'decorated' : 'plain');
    }
});

// -q and --silent
//
// Symfony's --help says -q means "Only errors are displayed. All other output
// is suppressed", and the README says quiet "does not change what data is
// shown". Both hold if a command's result and its errors survive -q and only
// --silent removes them, while incidental messages go under -q.

test('-q still prints what was asked for: the source, the lists and the JSON', function () {
    $cases = [
        [['command' => 'view', 'program' => 'x.php'], "<?php\necho 1;\n", "<?php\necho 1;\n"],
        [['command' => 'languages'], LANGUAGES_BODY, "\nSupported Languages\n-------------------\n\n * c\n * c#\n * c++\n * php\n\n"],
        [['command' => 'programs', 'language' => 'php'], PROGRAMS_BODY, "\nPrograms in php\n---------------\n\n * factorial.php\n * hello_world.php\n\n"],
    ];

    foreach ($cases as [$input, $body, $expected]) {
        $mock = new Mock([Mock::text($body)]);
        $tester = $mock->run($input + ['-q' => true]);

        assertSame(0, $tester->getStatusCode(), $input['command'] . ' exit');
        assertSame($expected, $tester->getDisplay(true), $input['command'] . ' stdout');
    }

    $mock = new Mock([Mock::text(LANGUAGES_BODY)]);
    $tester = $mock->run(['command' => 'languages', '--json' => true, '-q' => true]);
    assertSame(['languages' => ['c', 'c#', 'c++', 'php']], json_decode($tester->getDisplay(), true), '--json -q');
});

test('-q still reports errors, on stderr, with the hint', function () {
    $mock = new Mock([Mock::text("'rust' is currently not supported.\n", 404)]);
    $tester = $mock->run(['command' => 'programs', 'language' => 'rust', '-q' => true]);

    assertSame(1, $tester->getStatusCode(), 'exit');
    assertSame('', $tester->getDisplay(), 'stdout');
    assertContains("[ERROR] 'rust' is currently not supported.", errorText($tester), 'error');
    assertContains('Run "cscli languages"', errorText($tester), 'hint');
});

test('-q hides the incidental messages: the save confirmation and the empty-list warning', function () {
    $target = Fixture::directory('quiet') . DIRECTORY_SEPARATOR . 'out.php';

    $mock = new Mock([Mock::text('source')]);
    $saved = $mock->run(['command' => 'view', 'program' => 'x.php', '-o' => $target, '-q' => true]);
    assertSame(0, $saved->getStatusCode(), 'save exit');
    assertSame('source', (string) file_get_contents($target), 'file written');
    assertSame('', $saved->getDisplay() . $saved->getErrorOutput(), 'no confirmation');

    $mock = new Mock([Mock::text("Programs in empty: (none found)\n")]);
    $empty = $mock->run(['command' => 'programs', 'language' => 'empty', '-q' => true]);
    assertSame(0, $empty->getStatusCode(), 'empty exit');
    assertSame('', $empty->getDisplay() . $empty->getErrorOutput(), 'no warning');
});

test('-q will not overwrite a file, and says why it stopped', function () {
    $target = Fixture::directory('quiet') . DIRECTORY_SEPARATOR . 'out.php';
    file_put_contents($target, 'keep me');

    $mock = new Mock([Mock::text('new')]);
    $tester = $mock->run(['command' => 'view', 'program' => 'x.php', '-o' => $target, '-q' => true]);

    assertSame(1, $tester->getStatusCode(), 'exit');
    assertSame('keep me', (string) file_get_contents($target), 'untouched');
    assertContains('Aborted.', $tester->getErrorOutput(), 'reason');
});

test('--silent prints nothing at all, and the exit code still tells', function () {
    $mock = new Mock([Mock::text("<?php\n")]);
    $ok = $mock->run(['command' => 'view', 'program' => 'x.php', '--silent' => true]);
    assertSame(0, $ok->getStatusCode(), 'success exit');
    assertSame('', $ok->getDisplay() . $ok->getErrorOutput(), 'success output');

    $mock = new Mock([Mock::text('gone', 404)]);
    $failed = $mock->run(['command' => 'view', 'program' => 'x.php', '--silent' => true]);
    assertSame(1, $failed->getStatusCode(), 'failure exit');
    assertSame('', $failed->getDisplay() . $failed->getErrorOutput(), 'failure output');
});

test('-v changes nothing about the result', function () {
    $mock = new Mock([Mock::text("<?php\n")]);
    $tester = $mock->run(['command' => 'view', 'program' => 'x.php', '-v' => true]);

    assertSame("<?php\n", $tester->getDisplay(), 'stdout');
});

// Names the server sends that the console must not reinterpret

test('--json survives a name that is not valid UTF-8', function () {
    $mock = new Mock([Mock::text("Programs in php:\n- caf\xE9.php\n- ok.php\n")]);
    $tester = $mock->run(['command' => 'programs', 'language' => 'php', '--json' => true]);

    assertSame(0, $tester->getStatusCode(), 'exit');
    assertSame(
        ['language' => 'php', 'programs' => ["caf\u{FFFD}.php", 'ok.php']],
        json_decode($tester->getDisplay(), true),
        'the bad byte becomes U+FFFD, as cscli-api does in its own JSON'
    );
});

test('--json is written raw, so a backslash before < survives', function () {
    $mock = new Mock([Mock::text("Supported Languages:\n- a\<b\n- <info>c</info>\n")]);
    $tester = $mock->run(['command' => 'languages', '--json' => true]);

    assertSame(['languages' => ['a\<b', '<info>c</info>']], json_decode($tester->getDisplay(), true), 'decoded');
});

test('lists show names literally, not as console markup', function () {
    $mock = new Mock([Mock::text("Programs in <error>:\n- <info>x</info>.php\n- a\<b.php\n")]);
    $tester = $mock->run(['command' => 'programs', 'language' => '<error>']);

    $display = $tester->getDisplay(true);
    assertContains('Programs in <error>', $display, 'the language in the heading');
    assertContains(' * <info>x</info>.php', $display, 'a name that looks like a tag');
    assertContains(' * a\<b.php', $display, 'a backslash before <');
});

// view --language

test('view --language and -l name the language in the request', function () {
    foreach (['--language', '-l'] as $flag) {
        $mock = new Mock([Mock::text("print(1)\n")]);
        $tester = $mock->run(['command' => 'view', 'program' => 'factorial.php', $flag => 'python']);

        assertSame('/view/python/factorial.php', $mock->lastPath(), "$flag route");
        assertSame("print(1)\n", $tester->getDisplay(), "$flag stdout");
    }
});

test('view without --language asks the one-segment route, as it always has', function () {
    $mock = new Mock([Mock::text('x')]);
    $mock->run(['command' => 'view', 'program' => 'factorial.php']);

    assertSame('/view/factorial.php', $mock->lastPath(), 'route');
});
