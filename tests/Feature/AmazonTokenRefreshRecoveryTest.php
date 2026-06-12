<?php

use App\Services\AmazonSpApiApplicationManagementService;
use App\Support\AmazonTokenRefreshRecovery;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::forget(AmazonTokenRefreshRecovery::ROTATION_LOCK_KEY);
});

it('triggers rotation when amazon refresh fails with invalid_request', function () {
    $service = $this->mock(AmazonSpApiApplicationManagementService::class);
    $service->shouldReceive('rotateApplicationClientSecret')
        ->once()
        ->andReturn([
            'status' => 204,
            'request_id' => 'req-123',
            'rate_limit' => '0.5',
        ]);

    $exception = new RequestException(
        'Bad request',
        new Request('POST', 'https://api.amazon.com/auth/O2/token'),
        new Response(400, [], json_encode(['error' => 'invalid_request']))
    );

    $triggered = AmazonTokenRefreshRecovery::maybeRotateOnInvalidRequest($exception, [
        'receipt_account_id' => 31,
    ]);

    expect($triggered)->toBeTrue();
});

it('does not rotate when refresh fails for another error code', function () {
    $service = $this->mock(AmazonSpApiApplicationManagementService::class);
    $service->shouldNotReceive('rotateApplicationClientSecret');

    $exception = new RequestException(
        'Unauthorized',
        new Request('POST', 'https://api.amazon.com/auth/O2/token'),
        new Response(401, [], json_encode(['error' => 'unauthorized_client']))
    );

    $triggered = AmazonTokenRefreshRecovery::maybeRotateOnInvalidRequest($exception, [
        'receipt_account_id' => 31,
    ]);

    expect($triggered)->toBeFalse();
});

it('applies cooldown so duplicate invalid_request does not rotate repeatedly', function () {
    $service = $this->mock(AmazonSpApiApplicationManagementService::class);
    $service->shouldReceive('rotateApplicationClientSecret')
        ->once()
        ->andReturn([
            'status' => 204,
            'request_id' => 'req-123',
            'rate_limit' => '0.5',
        ]);

    $exception = new RequestException(
        'Bad request',
        new Request('POST', 'https://api.amazon.com/auth/O2/token'),
        new Response(400, [], json_encode(['error' => 'invalid_request']))
    );

    $first = AmazonTokenRefreshRecovery::maybeRotateOnInvalidRequest($exception, [
        'receipt_account_id' => 31,
    ]);
    $second = AmazonTokenRefreshRecovery::maybeRotateOnInvalidRequest($exception, [
        'receipt_account_id' => 31,
    ]);

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse();
});
