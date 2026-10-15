<?php

declare(strict_types=1);

namespace Cscli\Command;

use Cscli\Api\ApiClient;
use Cscli\Api\ApiException;
use Cscli\Api\ApiNotFoundException;
use Cscli\Console\Verbosity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'view',
    description: 'View the source code of a program',
)]
final class ViewCommand extends Command
{
    public function __construct(private readonly ApiClient $api)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('program', InputArgument::REQUIRED, 'Program filename, e.g. factorial.php')
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Write the source to a file instead of printing it'
            )
            ->addOption(
                'language',
                'l',
                InputOption::VALUE_REQUIRED,
                'The language the program is in, when more than one has a file of that name'
            )
            ->setHelp(<<<'HELP'
                The <info>view</info> command prints the source code of a single program.

                By default the source is written to stdout, which means it composes
                cleanly with pipes and redirection:

                  <info>cscli view factorial.php</info>
                  <info>cscli view factorial.php | less</info>
                  <info>cscli view factorial.php > factorial.php</info>

                Or write directly to a file with <info>--output</info>:

                  <info>cscli view factorial.php --output ./factorial.php</info>

                A name on its own is looked for in every language, alphabetically,
                and the first match is printed. Name the language to get another:

                  <info>cscli view factorial.php --language python</info>
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errors = Verbosity::errors($input, $output);
        $program = (string) $input->getArgument('program');
        $outputPath = $input->getOption('output');
        $language = $input->getOption('language');

        try {
            $source = $this->api->viewProgram($program, $language);
        } catch (ApiNotFoundException $e) {
            $errors->error($e->getMessage());
            return Command::FAILURE;
        } catch (ApiException $e) {
            $errors->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($outputPath !== null) {
            if (file_exists($outputPath) && !$io->confirm("'$outputPath' already exists. Overwrite?", false)) {
                $errors->writeln('Aborted.');
                return Command::FAILURE;
            }

            if (@file_put_contents($outputPath, $source) === false) {
                $errors->error("Could not write to '$outputPath'.");
                return Command::FAILURE;
            }

            if (!$output->isQuiet()) {
                $io->success("Saved to $outputPath");
            }
            return Command::SUCCESS;
        }

        // OUTPUT_RAW, because write() otherwise runs the text through the
        // console formatter: "<info>x</info>" became colour codes or plain
        // "x", "</>" vanished and "\<" lost its backslash.
        Verbosity::result($output)->write($source, false, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }
}
