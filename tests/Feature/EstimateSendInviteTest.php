<?php

use App\Livewire\Estimates\EstimateShow;
use App\Mail\EstimateSigningInvite;
use App\Mail\WelcomeClient;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateSignature;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeEstimateInviteUser(string $firstName, string $lastName, string $email): User
{
    return User::query()->create([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
        'remember_token' => Str::random(10),
    ]);
}

it('sends contract signing invites only to unsigned client users when vendor has already signed', function () {
    Mail::fake();

    $vendor = Vendor::query()->create([
        'business_name' => 'Hive GC',
        'business_type' => 'Sub',
        'address' => '123 Main St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]);

    $admin = makeEstimateInviteUser('Vendor', 'Admin', 'vendor-admin@example.test');
    $admin->forceFill(['primary_vendor_id' => $vendor->id])->saveQuietly();

    $vendor->users()->attach($admin->id, [
        'is_employed' => true,
        'role_id' => 1,
    ]);

    $david = makeEstimateInviteUser('David', 'Signer', 'david@example.test');

    $hanna = makeEstimateInviteUser('Hanna', 'Pending', 'hanna@example.test');

    $client = Client::query()->create([
        'business_name' => 'Client Household',
        'address' => '456 Oak St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]);

    $client->vendors()->attach($vendor->id, ['source' => 'test']);
    $client->users()->attach([$david->id, $hanna->id]);

    $this->actingAs($admin);

    $project = Project::query()->create([
        'project_name' => 'Kitchen Remodel',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '456 Oak St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]);

    $estimate = Estimate::withoutGlobalScopes()->create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'options' => [],
    ]);

    EstimateSignature::query()->create([
        'estimate_id' => $estimate->id,
        'user_id' => $admin->id,
        'signer_name' => 'Vendor Admin',
        'signer_email' => $admin->email,
        'signer_phone' => '+12243334444',
        'signature_data' => 'admin-signature',
        'signature_type' => 'draw',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit',
        'document_hash' => 'hash-admin',
        'signed_at' => now()->subDay(),
    ]);

    EstimateSignature::query()->create([
        'estimate_id' => $estimate->id,
        'user_id' => $david->id,
        'signer_name' => 'David Signer',
        'signer_email' => $david->email,
        'signer_phone' => '+12243335555',
        'signature_data' => 'david-signature',
        'signature_type' => 'draw',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit',
        'document_hash' => 'hash-david',
        'signed_at' => now()->subHours(20),
    ]);

    \Flux::shouldReceive('toast')->andReturnNull();

    $component = app(EstimateShow::class);
    $component->estimate = $estimate->fresh();
    $component->sendInvite();

    Mail::assertSent(EstimateSigningInvite::class, 1);
    Mail::assertSent(EstimateSigningInvite::class, function (EstimateSigningInvite $mail) use ($hanna): bool {
        return $mail->hasTo($hanna->email);
    });
    Mail::assertNotSent(EstimateSigningInvite::class, function (EstimateSigningInvite $mail) use ($david): bool {
        return $mail->hasTo($david->email);
    });

    Mail::assertNotSent(WelcomeClient::class);
});
