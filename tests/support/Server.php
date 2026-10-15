<?php

declare(strict_types=1);

/**
 * `php -S` servers for the end-to-end tests, started on demand and stopped
 * when the run ends.
 *
 * Two traps, both found the hard way in Web/PHP/cscli-api and both avoided
 * here. The server's output goes to a file rather than a pipe: it logs a line
 * per request, and a pipe that nothing reads fills and blocks the server
 * mid-response. And the command is an array, which proc_open() runs without
 * cmd.exe, so proc_terminate() stops the server itself rather than a shell
 * with the server orphaned underneath it.
 */
final class Server
{
    /** @var array<string, string|null> base URL by key, null if it could not start */
    private static array $urls = [];

    /** @var list<resource> */
    private static array $processes = [];

    /**
     * The sibling cscli-api, if it is there and is a version with a route
     * table the tests can load, or null.
     */
    public static function apiDirectory(): ?string
    {
        $dir = getenv('CSCLI_API_DIR') ?: dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'cscli-api';

        return is_file($dir . '/utils/dispatch.php') ? $dir : null;
    }

    /** The real API serving the fixture tree, or null if it cannot be started. */
    public static function api(): ?string
    {
        $api = self::apiDirectory();

        if ($api === null) {
            return null;
        }

        return self::start('api', __DIR__ . '/api-router.php', [
            'CSCLI_API_DIR' => $api,
            'CSCLI_FIXTURE_ROOT' => Fixture::sources(),
        ]);
    }

    /**
     * A server answering from a fixed table of path => [status, body].
     *
     * @param array<string, array{0: int, 1: string}> $routes
     */
    public static function canned(array $routes): ?string
    {
        $key = 'canned-' . md5(serialize($routes));

        if (array_key_exists($key, self::$urls)) {
            return self::$urls[$key];
        }

        $table = Fixture::directory('canned') . DIRECTORY_SEPARATOR . 'routes.json';
        $encoded = array_map(static fn (array $r) => [$r[0], base64_encode($r[1])], $routes);
        file_put_contents($table, json_encode($encoded));

        return self::start($key, __DIR__ . '/canned-router.php', ['CSCLI_CANNED' => $table]);
    }

    /** @param array<string, string> $env */
    private static function start(string $key, string $router, array $env): ?string
    {
        if (array_key_exists($key, self::$urls)) {
            return self::$urls[$key];
        }

        self::$urls[$key] = null;
        $port = self::freePort();

        if ($port === null) {
            return null;
        }

        $log = Fixture::directory('server') . DIRECTORY_SEPARATOR . 'server.log';
        $pipes = [];
        $process = @proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", $router],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            __DIR__,
            $env + getenv(),
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);

        if (self::$processes === []) {
            register_shutdown_function([self::class, 'stopAll']);
        }

        self::$processes[] = $process;

        // Poll rather than sleep a guessed interval: the server takes a moment
        // to bind, and how long depends on the machine.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);

            if ($socket !== false) {
                fclose($socket);
                return self::$urls[$key] = "http://127.0.0.1:$port";
            }

            usleep(50_000);
        }

        return null;
    }

    public static function stopAll(): void
    {
        foreach (self::$processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }

        self::$processes = [];
    }

    /** Asks the OS for a port nobody is using, then gives it straight back. */
    private static function freePort(): ?int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            return null;
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $port = (int) substr($name, strrpos($name, ':') + 1);

        return $port > 0 ? $port : null;
    }
}
