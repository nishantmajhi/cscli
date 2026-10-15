<?php

declare(strict_types=1);

namespace Cscli\Command;

use Cscli\Api\ApiClient;
use Cscli\Api\ApiException;
use Cscli\Api\ApiNotFoundException;
use Cscli\Console\Listing;
use Cscli\Console\Verbosity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'programs',
    description: 'List the available programs for a language',
)]
final class ProgramsCommand extends Command
{
    public function __construct(private readonly ApiClient $api)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('language', InputArgument::REQUIRED, 'Language to list programs for, e.g. php')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON instead of plain text')
            ->setHelp(<<<'HELP'
                The <info>programs</info> command shows every program available for a given
                language. Run <info>cscli languages</info> first if you're not sure which
                language names are valid.

                Examples:

                  <info>cscli programs php</info>
                  <info>cscli programs python --json</info>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = Verbosity::result($output);
        $errors = Verbosity::errors($input, $output);
        $language = (string) $input->getArgument('language');

        try {
            $raw = $this->api->listPrograms($language);
        } catch (ApiNotFoundException $e) {
            $errors->error($e->getMessage());
            $errors->note('Run "cscli languages" to see the supported language names.');
            return Command::FAILURE;
        } catch (ApiException $e) {
            $errors->error($e->getMessage());
            return Command::FAILURE;
        }

        $programs = Listing::parse($raw);

        if ($input->getOption('json')) {
            Listing::json($result, ['language' => $language, 'programs' => $programs]);
            return Command::SUCCESS;
        }

        if ($programs === []) {
            $io->warning("No programs found for '$language'.");
            return Command::SUCCESS;
        }

        Listing::show($input, $result, "Programs in $language", $programs);

        return Command::SUCCESS;
    }
}
