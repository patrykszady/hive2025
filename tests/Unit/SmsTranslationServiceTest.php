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

it('keeps task and address lines unchanged when translating schedule messages', function (): void {
    config()->set('services.openai.api_key', 'test-key');
    Cache::flush();

    Http::fake([
        'https://api.openai.com/v1/chat/completions' => function ($request) {
            $userContent = (string) data_get($request->data(), 'messages.1.content', '');

            $translated = str_replace('Hi RG Tile,', 'Czesc RG Tile,', $userContent);
            $translated = str_replace('Confirm Tasks:', 'Potwierdz zadania:', $translated);
            $translated = str_replace('Tomorrow Tuesday 30/06:', 'Jutro wtorek 30/06:', $translated);
            $translated = str_replace('Confirm Schedule:', 'Potwierdz harmonogram:', $translated);

            return Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => $translated,
                        ],
                    ],
                ],
            ], 200);
        },
    ]);

    $text = implode("\n", [
        'Hi RG Tile,',
        'Confirm Tasks:',
        '',
        'Tomorrow Tuesday 30/06:',
        '- Tile/grout repair @ 7-8AM',
        '5328 Oak Grove Dr',
        'Long Grove, IL 60047',
        '- Grout repair @ 10AM-12PM',
        '4100 N Kennicott Ave',
        'Arlington Heights, IL 60004',
        '',
        'Confirm Schedule: https://hive.contractors/v/82539ea820e3a918',
    ]);

    $translated = app(SmsTranslationService::class)->translate($text, 'Polish', 'English');

    expect($translated)
        ->toContain('Czesc RG Tile,')
        ->toContain('Potwierdz zadania:')
        ->toContain('Tomorrow Tuesday 30/06:')
        ->not->toContain('Jutro wtorek 30/06:')
        ->toContain('- Tile/grout repair @ 7-8AM')
        ->toContain('5328 Oak Grove Dr')
        ->toContain('Long Grove, IL 60047')
        ->toContain('- Grout repair @ 10AM-12PM')
        ->toContain('4100 N Kennicott Ave')
        ->toContain('Arlington Heights, IL 60004')
        ->toContain('Potwierdz harmonogram: https://hive.contractors/v/82539ea820e3a918');
});
