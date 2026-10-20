<?php

use App\Livewire\VendorDocs\VendorDocCreate;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDoc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * VendorDocCreate::store() had no authorization check of its own — unlike
 * requestDocument() on the same component, which got a "defense in depth"
 * check in 0131918d ("the blade only shows this button when $canCreateDoc,
 * but this callable method had no server-side check of its own"), and
 * unlike the app API's equivalent, StoreVendorDocRequest::authorize()
 * (gating VendorDocController::store()), which checks
 * $user->can('create', VendorDoc::class) — i.e. VendorDocPolicy::create(),
 * Admin-only. Fixed by adding the same authorize('create', VendorDoc::
 * class) call to store().
 */
function vendorDocCreateFixture(int $role): array
{
    $gc = Vendor::factory()->create(['business_type' => 'GC']);
    $user = User::query()->create([
        'first_name' => 'Docs', 'last_name' => 'User',
        'email' => 'vdoc-'.uniqid().'@example.test',
        'cell_phone' => '555'.rand(1000000, 9999999),
        'primary_vendor_id' => $gc->id,
    ]);
    $gc->users()->attach($user->id, ['role_id' => $role]);

    return compact('gc', 'user');
}

it('refuses a Member uploading a vendor document', function () {
    ['user' => $member] = vendorDocCreateFixture(role: 2);

    Livewire::actingAs($member)
        ->test(VendorDocCreate::class)
        ->call('store')
        ->assertForbidden();

    expect(VendorDoc::withoutGlobalScopes()->count())->toBe(0);
});

it('lets an Admin past the authorization check into validation', function () {
    ['user' => $admin] = vendorDocCreateFixture(role: 1);

    // An unsupported mime type: proves store() reached $this->validate()
    // rather than being blocked by authorization (a 403 would never
    // surface as a field-level "doc_file" error).
    $file = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

    Livewire::actingAs($admin)
        ->test(VendorDocCreate::class)
        ->set('doc_file', $file)
        ->call('store')
        ->assertHasErrors(['doc_file' => 'mimes']);

    expect(VendorDoc::withoutGlobalScopes()->count())->toBe(0);
});
