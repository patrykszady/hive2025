<?php

use App\Livewire\VendorDocs\VendorDocsCard;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDoc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// The vendor docs card lists the latest document of each type; everything
// older sits in a "Previous / expired" accordion under it (2026-10-13).

it('folds the older documents of each type into a previous / expired accordion', function () {
    $gc = Vendor::factory()->create(['business_type' => 'GC']);
    $user = User::query()->create(['first_name' => 'Docs', 'last_name' => 'Admin', 'email' => 'docs-'.uniqid().'@example.test', 'cell_phone' => '224'.rand(1000000, 9999999), 'primary_vendor_id' => $gc->id]);
    $user->vendors()->attach($gc->id, ['role_id' => 1]);
    $this->actingAs($user);
    $sub = Vendor::factory()->create(['business_type' => 'Sub']);
    $gc->vendors()->attach($sub->id);

    $mk = fn (string $type, string $number, string $from, string $to) => VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => $type, 'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $gc->id, 'number' => $number,
        'doc_filename' => $number.'.pdf', 'effective_date' => $from, 'expiration_date' => $to,
    ]));
    $mk('workers', 'WC-CURRENT', '2026-03-26', '2027-03-26');
    $mk('workers', 'WC-LAST-YEAR', '2025-03-26', '2026-03-26');
    $mk('workers', 'WC-OLDER', '2024-03-26', '2025-03-26');
    $mk('general', 'GL-CURRENT', '2026-01-01', '2027-01-01');

    Livewire::withoutLazyLoading();
    $card = Livewire::test(VendorDocsCard::class, ['vendor' => $sub, 'view' => true])
        ->assertSee('WC-CURRENT')
        ->assertSee('GL-CURRENT')
        ->assertSee('Previous / expired (2)')
        ->assertSee('WC-LAST-YEAR')
        ->assertSee('WC-OLDER');

    expect($card->get('vendor_docs')->pluck('number')->all())->toEqualCanonicalizing(['WC-CURRENT', 'GL-CURRENT'])
        ->and($card->get('previous_docs')->pluck('number')->all())->toBe(['WC-LAST-YEAR', 'WC-OLDER']);

    // Only current documents: no accordion.
    $only = Vendor::factory()->create(['business_type' => 'Sub']);
    $gc->vendors()->attach($only->id);
    VendorDoc::withoutEvents(fn () => VendorDoc::create(['type' => 'workers', 'vendor_id' => $only->id, 'belongs_to_vendor_id' => $gc->id, 'number' => 'WC-ONLY', 'doc_filename' => 'o.pdf', 'effective_date' => '2026-01-01', 'expiration_date' => '2027-01-01']));
    Livewire::withoutLazyLoading();
    Livewire::test(VendorDocsCard::class, ['vendor' => $only, 'view' => true])->assertSee('WC-ONLY')->assertDontSee('Previous / expired');
});
