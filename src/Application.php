<?php

declare(strict_types=1);

namespace Cscli;

use Cscli\Api\ApiClient;
use Cscli\Command\LanguagesCommand;
use Cscli\Command\ProgramsCommand;
use Cscli\Command\ViewCommand;
use Symfony\Component\Console\Application as ConsoleApplication;

final class Application
{
    public const VERSION = '1.0.0';

    public const DEFAULT_API_URL = 'http://localhost:6300';

    /** @param ApiClient|null $api Supplied by the tests; built from the environment otherwise. */
    public static function create(?ApiClient $api = null): ConsoleApplication
    {
        $api ??= new ApiClient(self::apiUrl());

        $app = new ConsoleApplication('cscli', self::VERSION);
        $app->addCommands([
            new LanguagesCommand($api),
            new ProgramsCommand($api),
            new ViewCommand($api),
        ]);

        // Leave the default command as Symfony's built-in "list" (shows all
        // available commands) rather than guessing what the user wants when
        // no subcommand is given - predictable beats clever here.

        return $app;
    }

    /** The API's base URL: CSCLI_API_URL if it is set and not empty, else the default. */
    public static function apiUrl(): string
    {
        return getenv('CSCLI_API_URL') ?: self::DEFAULT_API_URL;
    }
}
