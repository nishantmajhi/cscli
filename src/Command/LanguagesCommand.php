<?php

declare(strict_types=1);

namespace Cscli\Command;

use Cscli\Api\ApiClient;
use Cscli\Api\ApiException;
use Cscli\Console\Listing;
use Cscli\Console\Verbosity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'languages',
    description: 'List the programming languages supported by the API',
)]
final class LanguagesCommand extends Command
{
    public function __construct(private readonly ApiClient $api)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON instead of plain text')
            ->setHelp(<<<'HELP'
                The <info>languages</info> command lists every language the CSCLI API
                currently has source code for.

                Examples:

                  <info>cscli languages</info>
                  <info>cscli languages --json</info>    # for use in scripts/pipes
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = Verbosity::result($output);
        $errors = Verbosity::errors($input, $output);

        try {
            $raw = $this->api->languages();
        } catch (ApiException $e) {
            $errors->error($e->getMessage());
            return Command::FAILURE;
        }

        $languages = Listing::parse($raw);

        if ($input->getOption('json')) {
            Listing::json($result, ['languages' => $languages]);
            return Command::SUCCESS;
        }

        Listing::show($input, $result, 'Supported Languages', $languages);

        return Command::SUCCESS;
    }
}
