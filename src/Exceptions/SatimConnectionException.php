<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Exceptions;

use Throwable;

/**
 * Thrown when the gateway could not be reached or answered unusably.
 *
 * A SATIM response carrying an error code is NOT a connection failure: those
 * are reported through the result objects instead.
 */
class SatimConnectionException extends SatimException
{
    public static function requestFailed(string $url, Throwable $previous): self
    {
        return new self("The request to the SATIM gateway [$url] failed: {$previous->getMessage()}", 0, $previous);
    }

    public static function unexpectedStatus(string $url, int $status): self
    {
        return new self("The SATIM gateway [$url] responded with HTTP status $status.");
    }

    public static function invalidResponse(string $url): self
    {
        return new self("The SATIM gateway [$url] did not return a JSON object.");
    }
}
