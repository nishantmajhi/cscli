<?php

declare(strict_types=1);

/**
 * Runs bin/cscli as a separate process - either the working copy's, or the
 * first commit's, rebuilt from git into a scratch directory.
 *
 * The original cannot be loaded into this process: its classes have the same
 * names as the working copy's. So both are run the way a user runs them, as a
 * command against a real server, and the same assertions are made of each.
 */
final class Cli
{
    private static ?self $current = null;

    private static self|false|null $original = null;

    private function __construct(public readonly string $label, private readonly string $bin)
    {
    }

    public static function current(): self
    {
        return self::$current ??= new self('working copy', dirname(__DIR__, 2) . '/bin/cscli');
    }

    /**
     * The code as it was before this pass, or null if git cannot provide it.
     *
     * Its bin/ and src/ are written out of the root commit with `git show`,
     * and a vendor/autoload.php beside them loads this checkout's installed
     * dependencies but maps the Cscli namespace to the old src/. So the
     * original runs its own code with the same Symfony and Guzzle, and a
     * difference in behaviour is a difference in the project's code.
     */
    public static function original(): ?self
    {
        if (self::$original !== null) {
            return self::$original ?: null;
        }

        self::$original = false;
        $repo = dirname(__DIR__, 2);
        $git = static fn (string $args): ?string => self::git($repo, $args);

        $roots = $git('rev-list --max-parents=0 HEAD');

        if ($roots === null || trim($roots) === '') {
            return null;
        }

        $baseline = trim((string) strrchr("\n" . trim($roots), "\n"));
        $files = $git("ls-tree -r --name-only $baseline -- bin src");

        if ($files === null) {
            return null;
        }

        $root = Fixture::directory('original');

        foreach (array_filter(explode("\n", trim($files))) as $file) {
            $contents = $git("show $baseline:$file");

            if ($contents === null) {
                return null;
            }

            $target = $root . '/' . $file;

            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }

            file_put_contents($target, $contents);
        }

        mkdir($root . '/vendor');
        file_put_contents($root . '/vendor/autoload.php', sprintf(
            "<?php\n\$loader = require %s;\n\$loader->setPsr4('Cscli\\\\', [__DIR__ . '/../src/']);\nreturn \$loader;\n",
            var_export($repo . '/vendor/autoload.php', true)
        ));

        return self::$original = new self('original', $root . '/bin/cscli');
    }

    /**
     * Runs one command.
     *
     * stdin is closed at once, so a question gets end-of-input rather than
     * waiting. Output goes to files, which also means it is not a terminal:
     * Symfony decides on colour by itself, so TERM and friends are removed
     * from the environment to stop Git Bash's TERM=xterm turning it on.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    public function run(array $args, string $apiUrl, ?string $cwd = null, array $env = []): Result
    {
        $scratch = Fixture::directory('run');
        $out = $scratch . DIRECTORY_SEPARATOR . 'stdout';
        $err = $scratch . DIRECTORY_SEPARATOR . 'stderr';

        $environment = getenv();
        unset(
            $environment['TERM'],
            $environment['ANSICON'],
            $environment['ConEmuANSI'],
            $environment['SHELL_VERBOSITY'],
            $environment['NO_COLOR'],
        );
        $environment = ['CSCLI_API_URL' => $apiUrl, 'COLUMNS' => '120'] + $env + $environment;

        $pipes = [];
        $process = proc_open(
            array_merge([PHP_BINARY, $this->bin], $args),
            [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
            $pipes,
            $cwd ?? $scratch,
            $environment,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            throw new RuntimeException('could not start ' . $this->bin);
        }

        fclose($pipes[0]);
        $exit = proc_close($process);

        return new Result($exit, (string) file_get_contents($out), (string) file_get_contents($err));
    }

    private static function git(string $repo, string $args): ?string
    {
        $pipes = [];
        $process = @proc_open(
            'git -C ' . escapeshellarg($repo) . ' ' . $args,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($process)) {
            return null;
        }

        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? (string) $output : null;
    }
}

final class Result
{
    public function __construct(
        public readonly int $exit,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }

    /** stderr with the block padding and line breaks Symfony adds collapsed. */
    public function errorText(): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $this->stderr));
    }
}
