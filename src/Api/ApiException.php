<?php

declare(strict_types=1);

namespace Cscli\Api;

use RuntimeException;

/**
 * Raised whenever the API can't fulfil a request: connection failures,
 * unexpected HTTP statuses, or malformed responses.
 */
class ApiException extends RuntimeException
{
}
