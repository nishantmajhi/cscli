<?php

declare(strict_types=1);

use Cscli\Api\ApiClient;
use Cscli\Application;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * The in-process half: an ApiClient whose requests go to a Guzzle MockHandler
 * and are recorded, and the real Application run through ApplicationTester.
 *
 * ApplicationTester rather than CommandTester because it goes through
 * Application::run(), which is where -q, --silent and -n are turned into a
 * verbosity and an interactive flag. CommandTester skips that, so a test of
 * -q written with it would be testing a setting the user cannot reach.
 */
final class Mock
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public readonly ApiClient $client;

    /** @param list<Response|Throwable> $responses */
    public function __construct(array $responses, string $baseUri = 'http://api.test')
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $this->history = &$history;
        $this->client = new ApiClient($baseUri, $stack);
    }

    /** @var array<int, array{request: RequestInterface}> */
    private array $history;

    /** The URLs requested so far, in order. */
    public function urls(): array
    {
        return array_map(static fn (array $entry) => (string) $entry['request']->getUri(), $this->history);
    }

    /** The path requested last, still percent-encoded. */
    public function lastPath(): string
    {
        $last = end($this->history);

        return $last === false ? '' : $last['request']->getUri()->getPath();
    }

    /**
     * Runs the application with these arguments.
     *
     * @param array<string, mixed> $input  e.g. ['command' => 'view', 'program' => 'x', '-q' => true]
     * @param list<string>         $answers typed at any question, in order
     */
    public function run(array $input, array $answers = [], bool $interactive = false, bool $decorated = false): ApplicationTester
    {
        $app = Application::create($this->client);
        $app->setAutoExit(false);
        $app->setCatchExceptions(false);

        $tester = new ApplicationTester($app);
        $tester->setInputs($answers);
        $tester->run($input, [
            'capture_stderr_separately' => true,
            'interactive' => $interactive,
            'decorated' => $decorated,
        ]);

        return $tester;
    }

    public static function text(string $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'text/plain; charset=utf-8'], $body);
    }
}

/** stderr with the block padding and line breaks Symfony adds collapsed. */
function errorText(ApplicationTester $tester): string
{
    return trim((string) preg_replace('/\s+/', ' ', $tester->getErrorOutput()));
}
