<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Http;

use GtsMeghni\Satim\Data\SatimConfig;
use GtsMeghni\Satim\Exceptions\SatimConnectionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

/**
 * Transport for the SATIM REST API.
 *
 * SATIM expects form encoded POST bodies and answers with JSON, even for the
 * endpoints its documentation illustrates with query strings.
 */
final readonly class SatimClient
{
    public function __construct(
        private SatimConfig $config,
        private Factory $http,
    ) {}

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     *
     * @throws SatimConnectionException
     */
    public function post(string $endpoint, array $payload): array
    {
        $url = $this->config->baseUrl.'/'.ltrim($endpoint, '/');

        try {
            $response = $this->http
                ->asForm()
                ->connectTimeout($this->config->connectTimeout)
                ->timeout($this->config->timeout)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            throw SatimConnectionException::requestFailed($url, $e);
        }

        if ($response->failed()) {
            throw SatimConnectionException::unexpectedStatus($url, $response->status());
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw SatimConnectionException::invalidResponse($url);
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
