<?php

use App\Http\Controllers\CompanyEmailController;
use App\Jobs\ForwardCompanyEmailReceipts;
use App\Models\CompanyEmail;
use App\Services\NylasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('does not throw when forwarding controller fails for a mailbox', function () {
    $companyEmail = CompanyEmail::query()->create([
        'vendor_id' => 1,
        'email' => 'mailbox@example.test',
        'grant_id' => (string) Str::uuid(),
    ]);

    $controller = \Mockery::mock(CompanyEmailController::class, [\Mockery::mock(NylasService::class)])
        ->makePartial();

    $controller->shouldReceive('processCompanyEmailForwarding')
        ->once()
        ->andThrow(new RuntimeException('simulated forwarding failure'));

    $job = new ForwardCompanyEmailReceipts($companyEmail->id);

    expect(fn () => $job->handle($controller))->not->toThrow(RuntimeException::class);
});
