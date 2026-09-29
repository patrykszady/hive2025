<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Route::middleware('web')->get('/_test/who', fn () => (string) (auth()->id() ?? 'guest'));
});

function passwordlessRememberedUser(): User
{
    $user = User::query()->create([
        'first_name' => 'Erin',
        'last_name' => 'Passwordless',
        'email' => 'erin.passwordless-'.uniqid().'@example.test',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
    ]);
    $user->forceFill(['remember_token' => Str::random(60)])->save();

    return $user->refresh();
}

it('treats a stale remember-me cookie of a passwordless user as a guest instead of failing', function (): void {
    $user = passwordlessRememberedUser();
    $guard = Auth::guard('web');

    $this->withCookie($guard->getRecallerName(), $user->id.'|'.$user->remember_token.'|'.str_repeat('0', 64))
        ->get('/_test/who')
        ->assertOk()
        ->assertSee('guest');
});

it('still signs a passwordless user back in from a valid remember-me cookie', function (): void {
    $user = passwordlessRememberedUser();
    $guard = Auth::guard('web');

    $this->withCookie($guard->getRecallerName(), $user->id.'|'.$user->remember_token.'|'.$guard->hashPasswordForCookie(''))
        ->get('/_test/who')
        ->assertOk()
        ->assertSee((string) $user->id);
});
