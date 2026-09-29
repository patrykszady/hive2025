<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Route::middleware('web')->group(function (): void {
        Route::get('/_test/forbidden-abort', fn () => abort(403));
        Route::post('/_test/forbidden-policy', fn () => throw new AuthorizationException('This action is unauthorized.'));
        Route::get('/_test/allowed', fn () => 'ok');
    });

    Log::spy();
});

it('logs a 403 from abort() with the signed-in user and the path', function (): void {
    $user = User::query()->create([
        'first_name' => 'Forbidden',
        'last_name' => 'Tester',
        'email' => 'forbidden.tester-'.uniqid().'@example.test',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
    ]);

    $this->actingAs($user)->get('/_test/forbidden-abort')->assertForbidden();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === '403 Forbidden: GET /_test/forbidden-abort'
        && $context['user_id'] === $user->id
        && $context['user_email'] === $user->email)->once();
});

it('names the Livewire component and method a refused update asked for', function (): void {
    $this->withHeaders(['X-Livewire' => '1'])->postJson('/_test/forbidden-policy', [
        'components' => [[
            'snapshot' => json_encode(['memo' => ['name' => 'users.user-create']]),
            'calls' => [['method' => 'save', 'params' => []]],
        ]],
    ])->assertForbidden();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === '403 Forbidden: POST /_test/forbidden-policy'
        && $context['reason'] === 'This action is unauthorized.'
        && $context['exception'] === AuthorizationException::class
        && $context['livewire'] === [['component' => 'users.user-create', 'calls' => ['save']]])->once();
});

it('logs nothing for an allowed request', function (): void {
    $this->get('/_test/allowed')->assertOk();

    Log::shouldNotHaveReceived('warning');
});
