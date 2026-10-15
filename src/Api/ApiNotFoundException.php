<?php

declare(strict_types=1);

namespace Cscli\Api;

/**
 * Raised specifically when the API responds with 404 - the requested
 * language or program doesn't exist. Kept distinct from ApiException so
 * commands can give a precise, actionable message instead of a generic
 * "something went wrong".
 */
final class ApiNotFoundException extends ApiException
{
}
