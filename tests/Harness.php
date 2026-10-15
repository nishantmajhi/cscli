<?php

/**
 * A very small test harness, the same one Web/PHP/cscli-api uses.
 *
 * No test framework is added for it. The suite needs only what the project
 * already depends on - Guzzle's MockHandler and Symfony's ApplicationTester -
 * and this is the minimum needed to express the tests clearly and to fail
 * loudly.
 */
final class Harness
{
    /** @var list<array{test: string, message: string}> */
    private static array $failures = [];

    private static int $assertions = 0;
    private static int $tests      = 0;
    private static string $current = '';

    private static bool $skipped = false;

    /** @var list<string> */
    private static array $passedNames = [];

    public static function test(string $name, callable $body): void
    {
        self::$tests++;
        self::$current = $name;
        self::$skipped = false;

        $failuresBefore = count(self::$failures);

        try {
            $body();
        } catch (Throwable $e) {
            self::fail(sprintf(
                'threw %s: %s (%s:%d)',
                $e::class,
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine()
            ));
        }

        if (count(self::$failures) === $failuresBefore) {
            self::$passedNames[] = $name;
        }
    }

    public static function fail(string $message): void
    {
        self::$failures[] = ['test' => self::$current, 'message' => $message];
    }

    public static function assertTrue(mixed $actual, string $message): void
    {
        self::$assertions++;

        if ($actual !== true) {
            self::fail($message . ' — expected true, got ' . self::describe($actual));
        }
    }

    public static function assertFalse(mixed $actual, string $message): void
    {
        self::$assertions++;

        if ($actual !== false) {
            self::fail($message . ' — expected false, got ' . self::describe($actual));
        }
    }

    public static function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        self::$assertions++;

        if ($expected !== $actual) {
            self::fail(sprintf(
                '%s — expected %s, got %s',
                $message,
                self::describe($expected),
                self::describe($actual)
            ));
        }
    }

    public static function assertNotSame(mixed $unexpected, mixed $actual, string $message): void
    {
        self::$assertions++;

        if ($unexpected === $actual) {
            self::fail($message . ' — expected a value other than ' . self::describe($unexpected));
        }
    }

    public static function assertNull(mixed $actual, string $message): void
    {
        self::$assertions++;

        if ($actual !== null) {
            self::fail($message . ' — expected null, got ' . self::describe($actual));
        }
    }

    public static function assertMatches(string $pattern, string $actual, string $message): void
    {
        self::$assertions++;

        if (preg_match($pattern, $actual) !== 1) {
            self::fail(sprintf('%s — %s does not match %s', $message, self::describe($actual), $pattern));
        }
    }

    /** Assert that running the callable throws. */
    public static function assertThrows(callable $body, string $message): void
    {
        self::$assertions++;

        try {
            $body();
        } catch (Throwable) {
            return;
        }

        self::fail($message . ' — expected an exception, none was thrown');
    }

    /**
     * Whether a body of assertions passes, without recording its failures.
     *
     * This is how a regression test proves it is testing something: the same
     * assertions are run against the original code, and they must not pass.
     */
    public static function passes(callable $body): bool
    {
        $failures = self::$failures;
        $assertions = self::$assertions;

        try {
            $body();
            $passed = count(self::$failures) === count($failures);
        } catch (Throwable) {
            $passed = false;
        }

        self::$failures = $failures;
        self::$assertions = $assertions;

        return $passed;
    }

    public static function skip(string $reason): void
    {
        self::$skipped = true;
        echo '  skip  ' . self::$current . ': ' . $reason . PHP_EOL;
    }

    /** Whether the running test has skipped. */
    public static function skipped(): bool
    {
        return self::$skipped;
    }

    /** Print the results and return the process exit code. */
    public static function summary(): int
    {
        echo PHP_EOL;

        foreach (self::$passedNames as $name) {
            echo "  ok    " . $name . PHP_EOL;
        }

        foreach (self::$failures as $failure) {
            echo "  FAIL  " . $failure['test'] . PHP_EOL;
            echo "        " . $failure['message'] . PHP_EOL;
        }

        echo PHP_EOL;
        echo sprintf(
            "%d tests, %d assertions, %d failures%s",
            self::$tests,
            self::$assertions,
            count(self::$failures),
            PHP_EOL
        );

        return self::$failures === [] ? 0 : 1;
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => '"' . $value . '"',
            is_bool($value)   => $value ? 'true' : 'false',
            is_null($value)   => 'null',
            is_array($value)  => 'array(' . json_encode($value, JSON_UNESCAPED_SLASHES) . ')',
            is_object($value) => $value::class,
            default           => (string) $value,
        };
    }
}

function test(string $name, callable $body): void
{
    Harness::test($name, $body);
}

function assertTrue(mixed $actual, string $message): void
{
    Harness::assertTrue($actual, $message);
}

function assertFalse(mixed $actual, string $message): void
{
    Harness::assertFalse($actual, $message);
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    Harness::assertSame($expected, $actual, $message);
}

function assertNotSame(mixed $unexpected, mixed $actual, string $message): void
{
    Harness::assertNotSame($unexpected, $actual, $message);
}

function assertNull(mixed $actual, string $message): void
{
    Harness::assertNull($actual, $message);
}

function assertMatches(string $pattern, string $actual, string $message): void
{
    Harness::assertMatches($pattern, $actual, $message);
}

function assertContains(string $needle, string $haystack, string $message): void
{
    Harness::assertTrue(str_contains($haystack, $needle), $message . ' — "' . $needle . '" not found in ' . var_export($haystack, true));
}

function assertNotContains(string $needle, string $haystack, string $message): void
{
    Harness::assertFalse(str_contains($haystack, $needle), $message . ' — "' . $needle . '" unexpectedly found in ' . var_export($haystack, true));
}

function fail(string $message): void
{
    Harness::fail($message);
}

function skip(string $reason): void
{
    Harness::skip($reason);
}

/**
 * A test of a fix, run against both the working copy and the original.
 *
 * The body receives a Cli and makes its assertions through it. It must pass
 * against the working copy, and it must fail against the first commit: a
 * regression test that passes on the unfixed code is testing nothing.
 */
function regression(string $name, callable $body): void
{
    Harness::test('regression: ' . $name, function () use ($body) {
        $body(Cli::current());

        // A body that skipped asserted nothing, and would assert nothing
        // against the original either - which would read as passing there.
        if (Harness::skipped()) {
            return;
        }

        $original = Cli::original();

        if ($original === null) {
            skip('git or the baseline commit is unavailable, so the original was not checked');
            return;
        }

        if (Harness::passes(fn () => $body($original))) {
            fail('passes against the original too, so it does not test the fix');
        }
    });
}
