<?php

use App\Models\Check;
use App\Models\CheckImage;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CheckImagePayeeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function resolverUser(string $first, string $last): User
{
    $user = User::query()->create([
        'first_name' => $first,
        'last_name'  => $last,
        'email'      => strtolower($first . '.' . $last . uniqid()) . '@example.com',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
    ]);

    $user->vendors()->attach(1, ['is_employed' => true, 'role_id' => 1]);

    return $user;
}

function resolverVendor(string $businessName): Vendor
{
    $vendor = Vendor::withoutGlobalScopes()->create([
        'business_name' => $businessName,
        'business_type' => 'Sub',
    ]);
    \DB::table('vendors_vendor')->insert([
        'vendor_id'            => $vendor->id,
        'belongs_to_vendor_id' => 1,
        'created_at'           => now(),
        'updated_at'           => now(),
    ]);

    return $vendor;
}

function resolverImage(array $overrides = []): CheckImage
{
    return CheckImage::create(array_merge([
        'image_filename'       => 'resolver_' . uniqid() . '.png',
        'check_number'         => 1001,
        'amount'               => 500.00,
        'payee'                => 'Gheyoa Szady',
        'belongs_to_vendor_id' => 1,
        'analyzed_at'          => now(),
    ], $overrides));
}

it('adopts the linked check payee as ground truth', function (): void {
    $user  = resolverUser('Grzegorz', 'Szady');
    $check = Check::create([
        'check_type' => 'Check', 'check_number' => 1001, 'date' => '2026-06-10',
        'amount' => 500.00, 'user_id' => $user->id, 'belongs_to_vendor_id' => 1,
        'created_by_user_id' => 0,
    ]);

    $image  = resolverImage(['check_id' => $check->id, 'payee' => 'Totally Unreadable']);
    $result = app(CheckImagePayeeResolver::class)->resolve($image);

    expect($result['source'])->toBe('check')
        ->and($image->fresh()->payee_user_id)->toBe($user->id)
        ->and($image->fresh()->payee_vendor_id)->toBeNull();
});

it('fuzzy-matches a mangled handwritten name to the company user', function (): void {
    $user = resolverUser('Grzegorz', 'Szady');
    resolverUser('Andzelina', 'Szady'); // close runner-up must not win
    resolverVendor('Phd Glass, Inc.');

    $image  = resolverImage(['payee' => 'Gheyoa Szady']);
    $result = app(CheckImagePayeeResolver::class)->resolve($image);

    expect($result)->not->toBeNull()
        ->and($result['source'])->toBe('fuzzy')
        ->and($image->fresh()->payee_user_id)->toBe($user->id);
});

it('fuzzy-matches a business payee to the company vendor', function (): void {
    resolverUser('Grzegorz', 'Szady');
    $vendor = resolverVendor('Smartech Electric, Inc');

    $image  = resolverImage(['payee' => 'SHARTECH ELECTRIC']);
    $result = app(CheckImagePayeeResolver::class)->resolve($image);

    expect($result)->not->toBeNull()
        ->and($image->fresh()->payee_vendor_id)->toBe($vendor->id)
        ->and($image->fresh()->payee_user_id)->toBeNull();
});

it('refuses low-similarity matches', function (): void {
    resolverUser('Grzegorz', 'Szady');
    resolverVendor('Phd Glass, Inc.');

    $image  = resolverImage(['payee' => 'Completely Different Name LLC']);
    $result = app(CheckImagePayeeResolver::class)->resolve($image);

    expect($result)->toBeNull()
        ->and($image->fresh()->payee_user_id)->toBeNull()
        ->and($image->fresh()->payee_vendor_id)->toBeNull();
});

it('refuses ambiguous matches without a clear winner', function (): void {
    resolverVendor('ACME Building One');
    resolverVendor('ACME Building Two');

    $image  = resolverImage(['payee' => 'ACME Building']);
    $result = app(CheckImagePayeeResolver::class)->resolve($image);

    expect($result)->toBeNull()
        ->and($image->fresh()->payee_vendor_id)->toBeNull();
});

it('never overwrites an existing resolution', function (): void {
    $user = resolverUser('Grzegorz', 'Szady');

    $image  = resolverImage(['payee_user_id' => 999]);
    $result = app(CheckImagePayeeResolver::class)->resolve($image);

    expect($result)->toBeNull()
        ->and($image->fresh()->payee_user_id)->toBe(999);
});
