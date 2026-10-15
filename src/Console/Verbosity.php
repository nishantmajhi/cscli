<?php

declare(strict_types=1);

namespace Cscli\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What -q may hide.
 *
 * Symfony's help describes -q as "Only errors are displayed. All other output
 * is suppressed", and the README promises that quiet "does not change what
 * data is shown". Both hold if output comes in three kinds:
 *
 * - a command's result (the source, a list, the JSON): shown under -q;
 * - its errors: shown under -q, on stderr;
 * - incidental messages (a save confirmation, an empty-list warning): hidden.
 *
 * --silent hides all three. The exit code is the only signal left then.
 */
final class Verbosity
{
    /** Where a command's result goes. */
    public static function result(OutputInterface $output): OutputInterface
    {
        return self::audible($output);
    }

    /** How a command reports an error. */
    public static function errors(InputInterface $input, OutputInterface $output): SymfonyStyle
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        return new SymfonyStyle($input, self::audible($errors));
    }

    /**
     * The same stream, at normal verbosity if it was quiet.
     *
     * SymfonyStyle's section() and listing() have no way to say "write this
     * even under -q", so rather than reimplement them the stream is reopened
     * one level louder. Silent is left alone: it is a separate level below
     * quiet, and silent means silent.
     */
    private static function audible(OutputInterface $output): OutputInterface
    {
        // Anything but a stream (a BufferedOutput, say) is left as it is: a
        // copy of one would write into its own copy of the buffer. The
        // console is always a stream.
        if ($output->getVerbosity() !== OutputInterface::VERBOSITY_QUIET || !$output instanceof StreamOutput) {
            return $output;
        }

        return new StreamOutput(
            $output->getStream(),
            OutputInterface::VERBOSITY_NORMAL,
            $output->isDecorated(),
            $output->getFormatter()
        );
    }
}
