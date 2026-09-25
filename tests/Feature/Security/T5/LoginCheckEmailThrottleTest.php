<?php

use App\Livewire\Auth\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => RateLimiter::clear('login-check-email:127.0.0.1'));

it('still reports "no account found" for an ordinary single attempt (UX unchanged)', function () {
    $component = Livewire::test(Login::class)
        ->set('identifier', 'nobody-'.uniqid().'@example.test')
        ->call('checkEmail');

    expect($component->errors()->first('identifier'))->toBe('No account found with this email address.');
});

it('throttles repeated checkEmail attempts from the same IP', function () {
    for ($i = 0; $i < 20; $i++) {
        Livewire::test(Login::class)
            ->set('identifier', 'nobody-'.$i.'-'.uniqid().'@example.test')
            ->call('checkEmail');
    }

    $component = Livewire::test(Login::class)
        ->set('identifier', 'nobody-final-'.uniqid().'@example.test')
        ->call('checkEmail');

    expect($component->errors()->first('identifier'))->toContain('Too many attempts');
});
