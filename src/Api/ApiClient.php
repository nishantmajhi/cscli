<?php

declare(strict_types=1);

namespace Cscli\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Thin wrapper around the CSCLI HTTP API.
 *
 * Every method returns plain text (the API's native format) and throws
 * ApiException on any failure, so commands don't need to know about
 * HTTP status codes or Guzzle internals.
 */
final class ApiClient
{
    private Client $http;

    /**
     * @param callable|null $handler A Guzzle handler to send requests through
     *                               instead of the network. The tests pass a
     *                               MockHandler; nothing else needs to.
     */
    public function __construct(string $baseUri, ?callable $handler = null)
    {
        $options = [
            'base_uri' => rtrim($baseUri, '/') . '/',
            'timeout' => 10,
            'http_errors' => false,
        ];

        if ($handler !== null) {
            $options['handler'] = $handler;
        }

        $this->http = new Client($options);
    }

    public function languages(): string
    {
        return $this->get('languages');
    }

    public function listPrograms(string $language): string
    {
        return $this->get('list/' . self::segment($language));
    }

    /**
     * @param string|null $language Without one, the server looks in every
     *                              language in alphabetical order and the
     *                              first file of that name wins.
     */
    public function viewProgram(string $program, ?string $language = null): string
    {
        $path = $language === null || $language === '' ? '' : self::segment($language) . '/';

        return $this->get('view/' . $path . self::segment($program));
    }

    /**
     * One name as one path segment.
     *
     * rawurlencode() leaves dots alone, and a segment that is exactly "." or
     * ".." is a path step: resolving "list/.." against the base URL gives the
     * base URL itself, so `cscli programs ..` listed the API's welcome text
     * as programs and exited 0. Encoded, it is a name like any other, and
     * the server says it has no such thing.
     */
    private static function segment(string $name): string
    {
        $encoded = rawurlencode($name);

        return $encoded === '.' || $encoded === '..' ? str_replace('.', '%2E', $encoded) : $encoded;
    }

    private function get(string $path): string
    {
        try {
            $response = $this->http->get($path);
        } catch (ConnectException $e) {
            throw new ApiException(
                "Could not reach the CSCLI API. Is the server running and is the URL correct?",
                previous: $e
            );
        } catch (RequestException|GuzzleException $e) {
            throw new ApiException("Request to the CSCLI API failed: " . $e->getMessage(), previous: $e);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status === 404) {
            throw new ApiNotFoundException(trim($body) !== '' ? trim($body) : 'Resource not found.');
        }

        if ($status >= 400) {
            throw new ApiException(
                "CSCLI API returned an unexpected error (HTTP $status): " . trim($body)
            );
        }

        return $body;
    }
}
