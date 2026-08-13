<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Data\Concerns;

/**
 * SATIM is inconsistent about response value types: the same field can arrive
 * as a string on one endpoint and as a number on another, and optional fields
 * are sometimes present but empty.
 */
trait ReadsSatimResponse
{
    /**
     * @param  array<string, mixed>  $response
     */
    private static function stringValue(array $response, string $key): ?string
    {
        $value = $response[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private static function intValue(array $response, string $key): ?int
    {
        $value = $response[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (int) trim($value);
        }

        return null;
    }

    /**
     * The nested "params" object, which carries respCode and respCode_desc.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private static function params(array $response): array
    {
        $params = $response['params'] ?? null;

        if (! is_array($params)) {
            return [];
        }

        $normalised = [];

        foreach ($params as $key => $value) {
            if (is_string($key)) {
                $normalised[$key] = $value;
            }
        }

        return $normalised;
    }
}
