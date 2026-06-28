<?php

use App\Services\SmsTranslationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

it('does not translate sms control keywords', function (): void {
    config()->set('services.openai.api_key', 'test-key');
    Http::fake();

    $translated = app(SmsTranslationService::class)->translate('START', 'Polish', 'English');

    expect($translated)->toBe('START');
    Http::assertNothingSent();
});

it('falls back to original text when model returns prompt-like response', function (): void {
    config()->set('services.openai.api_key', 'test-key');
    Cache::flush();

    Http::fake([
        'https://api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => 'Please provide the SMS text you would like translated.',
                    ],
                ],
            ],
        ], 200),
    ]);

    $translated = app(SmsTranslationService::class)->translate('Start', 'English');

    expect($translated)->toBe('Start');
});
