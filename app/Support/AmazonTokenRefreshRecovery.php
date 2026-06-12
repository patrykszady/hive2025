<?php

namespace App\Support;

use App\Services\AmazonSpApiApplicationManagementService;
use Carbon\Carbon;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AmazonTokenRefreshRecovery
{
    public const ROTATION_LOCK_KEY = 'amazon:spapi:rotation:invalid-request:cooldown';

    public static function maybeRotateOnInvalidRequest(RequestException $exception, array $context = []): bool
    {
        if (! self::isInvalidRequestError($exception)) {
            return false;
        }

        $locked = Cache::add(self::ROTATION_LOCK_KEY, true, Carbon::now()->addMinutes(15));
        if (! $locked) {
            Log::channel('receipt')->info('Amazon SP-API rotation skipped due to cooldown lock', $context);

            return false;
        }

        try {
            $result = app(AmazonSpApiApplicationManagementService::class)->rotateApplicationClientSecret();

            Log::channel('receipt')->warning('Triggered Amazon SP-API client secret rotation after refresh invalid_request', [
                ...$context,
                'status' => $result['status'] ?? null,
                'request_id' => $result['request_id'] ?? null,
            ]);

            return true;
        } catch (Throwable $rotationException) {
            Log::channel('receipt')->error('Automatic Amazon SP-API client secret rotation failed', [
                ...$context,
                'message' => $rotationException->getMessage(),
            ]);

            return false;
        }
    }

    protected static function isInvalidRequestError(RequestException $exception): bool
    {
        if (! $exception->hasResponse()) {
            return false;
        }

        $body = (string) $exception->getResponse()?->getBody();
        $decoded = json_decode($body, true);

        return is_array($decoded) && ($decoded['error'] ?? null) === 'invalid_request';
    }
}