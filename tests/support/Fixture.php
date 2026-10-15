<?php

declare(strict_types=1);

/**
 * A throwaway source tree for the real API to serve, and scratch directories.
 *
 * The tree is shaped to reach the defects: two languages with a file of the
 * same name, the two languages whose names need percent-encoding, and a
 * program whose text contains markup that Symfony Console's formatter would
 * otherwise act on.
 */
final class Fixture
{
    /** Source that the console formatter rewrites if it is ever passed through it. */
    public const MARKUP_SOURCE = "A <info>x</info> </> y \\< z\nif (a<b && c>d) {}\n";

    /** @var list<string> */
    private static array $cleanup = [];

    private static ?string $sources = null;

    /** The root of the source tree, built once per run. */
    public static function sources(): string
    {
        if (self::$sources !== null) {
            return self::$sources;
        }

        $root = self::directory('sources');

        $files = [
            'php/factorial.php' => "<?php\necho 'php';\n",
            'php/markup.php' => self::MARKUP_SOURCE,
            'python/factorial.php' => "print('python')\n",
            'python/hello.py' => "print('hello')\n",
            'c#/factorial.cs' => "class Factorial {}\n",
            'c++/factorial.cpp' => "int main() {}\n",
            'empty/.gitkeep' => '',
        ];

        foreach ($files as $path => $contents) {
            $file = $root . '/' . $path;

            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }

            file_put_contents($file, $contents);
        }

        return self::$sources = $root;
    }

    /** A new empty directory, deleted when the run ends. */
    public static function directory(string $label): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cscli-test-' . $label . '-' . bin2hex(random_bytes(4));
        mkdir($path, 0777, true);

        if (self::$cleanup === []) {
            register_shutdown_function([self::class, 'removeAll']);
        }

        self::$cleanup[] = $path;

        return $path;
    }

    public static function removeAll(): void
    {
        // A server still running holds its log open, and Windows will not
        // delete an open file.
        if (class_exists('Server', false)) {
            Server::stopAll();
        }

        foreach (self::$cleanup as $path) {
            self::remove($path);
        }

        self::$cleanup = [];
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . DIRECTORY_SEPARATOR . $entry);
            }
        }

        @rmdir($path);
    }
}
