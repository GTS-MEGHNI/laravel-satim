<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Exceptions;

/**
 * Thrown when the package is used with incomplete or invalid configuration.
 */
class SatimConfigurationException extends SatimException
{
    public static function missing(string $key): self
    {
        return new self("The SATIM configuration value [satim.$key] is required but missing.");
    }

    public static function invalid(string $key, string $reason): self
    {
        return new self("The SATIM configuration value [satim.$key] is invalid: $reason");
    }
}
