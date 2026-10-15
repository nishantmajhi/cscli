<?php

declare(strict_types=1);

namespace Cscli\Console;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A list of names from the API, read and written back out.
 *
 * The names are whatever is on the server's disk, so nothing here may treat
 * them as anything but text: not as console markup, and not as UTF-8 that is
 * guaranteed to be valid.
 */
final class Listing
{
    /**
     * The items of a "Heading:\n- one\n- two\n" body.
     *
     * The API's plain-text list format. The heading is dropped and rebuilt by
     * the caller, so the output is the same whatever the server calls it.
     *
     * @return list<string>
     */
    public static function parse(string $raw): array
    {
        $items = [];

        foreach (explode("\n", trim($raw)) as $line) {
            if (str_starts_with($line, '- ')) {
                $items[] = substr($line, 2);
            }
        }

        return $items;
    }

    /**
     * Pretty-printed JSON, written raw.
     *
     * A byte that is not UTF-8 becomes U+FFFD, as it does in cscli-api's own
     * JSON; without the flag json_encode() returned false and the command
     * died with a TypeError. Raw, because the formatter would otherwise turn
     * the JSON "a\\<b" into "a\<b", which no longer parses.
     *
     * @param array<string, mixed> $data
     */
    public static function json(OutputInterface $output, array $data): void
    {
        $output->writeln(
            json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE),
            OutputInterface::OUTPUT_RAW
        );
    }

    /**
     * A heading and a bulleted list, with every name escaped.
     *
     * SymfonyStyle's section() and listing() format what they are given, so
     * a name like "<info>x</info>" was shown as a green "x".
     *
     * @param list<string> $items
     */
    public static function show(InputInterface $input, OutputInterface $output, string $heading, array $items): void
    {
        $style = new SymfonyStyle($input, $output);
        $style->section(OutputFormatter::escape($heading));
        $style->listing(array_map([OutputFormatter::class, 'escape'], $items));
    }
}
