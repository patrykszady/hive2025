<?php

namespace App\Support;

use GuzzleHttp\Exception\RequestException;
use Throwable;

class ApiErrorFormatter
{
    /**
     * Format any Throwable (RequestException, Exception, Error) into a consistent array
     * for structured logging. Always preserves user supplied context first so it
     * appears at the top of Laravel's multi-line array log output.
     *
     * Enhancements:
     * - RequestException: include HTTP status, parsed JSON error/request_id when available
     * - Generic Throwable (Exception / Error): include exception class & code (if non‑zero)
     * - Always filters out null / empty string values while keeping 0 / false
     */
    public static function format(Throwable $e, array $context = []): array
    {
        if ($e instanceof RequestException) {
            $response = $e->getResponse();
            $decoded = null;
            if ($response) {
                $body = (string) $response->getBody();
                $maybe = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $decoded = $maybe;
                }
            }

            return array_filter([
                // user supplied context first so it's always present
                ...$context,
                'status' => $response?->getStatusCode(),
                'error' => $decoded['error'] ?? $e->getMessage(),
                'request_id' => $decoded['request_id'] ?? null,
                'exception_class' => $e::class,
                'code' => $e->getCode() ?: null,
            ], static fn($v) => $v !== null && $v !== '');
        }

        // Fallback for generic exceptions
        return array_filter([
            ...$context,
            'error' => $e->getMessage(),
            'exception_class' => $e::class,
            'code' => $e->getCode() ?: null,
        ], static fn($v) => $v !== null && $v !== '');
    }
}
