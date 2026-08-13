<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Contracts;

use GtsMeghni\Satim\Enums\SatimOperation;
use GtsMeghni\Satim\Exceptions\SatimConnectionException;
use GtsMeghni\Satim\Http\SatimClient;
use GtsMeghni\Satim\Testing\SatimFake;

/**
 * What carries a gateway call to SATIM and brings the answer back.
 *
 * The one seam the package swaps out under test: everything above it, from
 * validation to the audit trail, still runs against a fake transport, so a test
 * that passes has exercised the same code a real payment would.
 *
 * @see SatimClient The implementation used in production.
 * @see SatimFake The implementation Satim::fake() installs.
 */
interface SatimTransport
{
    /**
     * @param  array<string, string>  $payload  Credentials included, as SATIM expects them.
     * @return array<string, mixed> The decoded response.
     *
     * @throws SatimConnectionException When the gateway could not be reached or answered unusably.
     */
    public function post(SatimOperation $operation, array $payload): array;
}
