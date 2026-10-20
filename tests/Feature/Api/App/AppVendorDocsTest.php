<?php

use App\Jobs\SendVendorDocRequestEmail;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDoc;
use App\Services\VendorDocs\AppVendorDocDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/** @return array{vendor: Vendor, user: User} */
function appVendorDocsTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

/**
 * The OCR pipeline (App\Traits\ProcessesVendorDocs::handleVendorDocProcessing)
 * calls an external document-intelligence service — faked here the same way
 * tests/Feature/InsuranceMailboxAttachmentsTest.php fakes
 * VendorDocsController::handleVendorDocProcessing: an anonymous subclass
 * overriding just that one method, bound into the container so
 * VendorDocController's injected AppVendorDocDirectory resolves to it.
 *
 * @param  true|'duplicate'|'updated'|false  $result
 */
function appVendorDocsFakeProcessing($result): void
{
    app()->bind(AppVendorDocDirectory::class, function () use ($result) {
        return new class($result) extends AppVendorDocDirectory
        {
            public function __construct(private $result)
            {
            }

            public function store(Vendor $vendor, int $requestingVendorId, $file)
            {
                return $this->result;
            }
        };
    });
}

it('needs a token to list vendor docs', function () {
    $this->getJson('/api/app/v1/vendor-docs')->assertUnauthorized();
});

it('refuses a Member, like VendorDocPolicy on the web', function () {
    ['user' => $member] = appVendorDocsTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/vendor-docs')->assertForbidden();
});

it('lists a vendor with documents and its distinct doc-type count', function () {
    ['vendor' => $gc, 'user' => $admin] = appVendorDocsTeamUser(role: 1);
    $sub = Vendor::factory()->create(['business_type' => 'Sub']);
    $gc->vendors()->attach($sub->id);

    VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $gc->id,
        'number' => 'WC-1', 'doc_filename' => 'a.pdf', 'effective_date' => '2026-01-01', 'expiration_date' => '2027-01-01',
    ]));

    Sanctum::actingAs($admin, ['app']);
    $response = $this->getJson('/api/app/v1/vendor-docs')->assertOk();

    $row = collect($response->json('data'))->firstWhere('id', $sub->id);
    expect($row)->not->toBeNull()
        ->and($row['doc_type_count'])->toBe(1);
});

it('shows the latest document of each type, folding older ones into previous', function () {
    ['vendor' => $gc, 'user' => $admin] = appVendorDocsTeamUser(role: 1);
    $sub = Vendor::factory()->create(['business_type' => 'Sub']);
    $gc->vendors()->attach($sub->id);

    $mk = fn (string $number, string $from, string $to) => VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $gc->id, 'number' => $number,
        'doc_filename' => $number.'.pdf', 'effective_date' => $from, 'expiration_date' => $to,
    ]));
    $current = $mk('WC-CURRENT', '2026-03-26', '2027-03-26');
    $old = $mk('WC-OLD', '2025-03-26', '2026-03-26');

    Sanctum::actingAs($admin, ['app']);
    $response = $this->getJson("/api/app/v1/vendor-docs/{$sub->id}")->assertOk();

    expect(collect($response->json('data.current'))->pluck('number')->all())->toBe([$current->number])
        ->and(collect($response->json('data.previous'))->pluck('number')->all())->toBe([$old->number]);
});

it('rejects an unsupported file type before any OCR processing', function () {
    ['vendor' => $gc, 'user' => $admin] = appVendorDocsTeamUser(role: 1);
    $sub = Vendor::factory()->create(['business_type' => 'Sub']);
    $gc->vendors()->attach($sub->id);
    Sanctum::actingAs($admin, ['app']);

    $file = UploadedFile::fake()->create('doc.txt', 10, 'text/plain');

    $this->postJson("/api/app/v1/vendor-docs/{$sub->id}", ['doc_file' => $file])
        ->assertStatus(422)->assertJsonValidationErrors(['doc_file']);
});

it('uploads a certificate and reports it added', function () {
    ['vendor' => $gc, 'user' => $admin] = appVendorDocsTeamUser(role: 1);
    $sub = Vendor::factory()->create(['business_type' => 'Sub']);
    $gc->vendors()->attach($sub->id);
    Sanctum::actingAs($admin, ['app']);

    appVendorDocsFakeProcessing(true);
    $file = UploadedFile::fake()->create('coi.pdf', 50, 'application/pdf');

    $this->postJson("/api/app/v1/vendor-docs/{$sub->id}", ['doc_file' => $file])
        ->assertCreated()
        ->assertJsonPath('data.result', true);
});

it('reports a duplicate certificate without erroring', function () {
    ['vendor' => $gc, 'user' => $admin] = appVendorDocsTeamUser(role: 1);
    $sub = Vendor::factory()->create(['business_type' => 'Sub']);
    $gc->vendors()->attach($sub->id);
    Sanctum::actingAs($admin, ['app']);

    appVendorDocsFakeProcessing('duplicate');
    $file = UploadedFile::fake()->create('coi.pdf', 50, 'application/pdf');

    $this->postJson("/api/app/v1/vendor-docs/{$sub->id}", ['doc_file' => $file])
        ->assertOk()
        ->assertJsonPath('data.result', 'duplicate');
});

// --- REQUEST DOCUMENTS ------------------------------------------------------

it('needs a token to request documents', function () {
    $this->postJson('/api/app/v1/vendor-docs/1/request')->assertUnauthorized();
});

it('refuses a Member requesting documents', function () {
    ['vendor' => $gc, 'user' => $member] = appVendorDocsTeamUser(role: 2);
    $sub = Vendor::factory()->create(['business_type' => 'Sub']);
    $gc->vendors()->attach($sub->id);

    Sanctum::actingAs($member, ['app']);
    $this->postJson("/api/app/v1/vendor-docs/{$sub->id}/request")->assertForbidden();
});

it('reports nothing to request when no document is expired', function () {
    ['vendor' => $gc, 'user' => $admin] = appVendorDocsTeamUser(role: 1);
    $sub = Vendor::factory()->create(['business_type' => 'Sub']);
    $gc->vendors()->attach($sub->id);

    VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'workers', 'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $gc->id,
        'number' => 'WC-1', 'doc_filename' => 'a.pdf', 'effective_date' => now()->subMonths(6)->format('Y-m-d'),
        'expiration_date' => now()->addMonths(6)->format('Y-m-d'),
    ]));

    Bus::fake();
    Sanctum::actingAs($admin, ['app']);
    $this->postJson("/api/app/v1/vendor-docs/{$sub->id}/request")
        ->assertOk()
        ->assertJsonPath('data.sent', false);

    Bus::assertNotDispatched(SendVendorDocRequestEmail::class);
});

it('emails a request for an expired document, exactly like the web\'s "Request" button', function () {
    ['vendor' => $gc, 'user' => $admin] = appVendorDocsTeamUser(role: 1);
    $sub = Vendor::factory()->create(['business_type' => 'Sub', 'business_email' => 'sub@example.test']);
    $gc->vendors()->attach($sub->id);

    VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => 'general', 'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $gc->id,
        'number' => 'GL-1', 'doc_filename' => 'a.pdf', 'effective_date' => now()->subYears(2)->format('Y-m-d'),
        'expiration_date' => now()->subYear()->format('Y-m-d'),
    ]));

    Bus::fake();
    Sanctum::actingAs($admin, ['app']);
    $this->postJson("/api/app/v1/vendor-docs/{$sub->id}/request")
        ->assertOk()
        ->assertJsonPath('data.sent', true)
        ->assertJsonPath('data.count', 1);

    Bus::assertDispatched(SendVendorDocRequestEmail::class);
});

// --- AUDIT (minimal read) -------------------------------------------------

it('needs a token for the audit banks list', function () {
    $this->getJson('/api/app/v1/vendor-docs/audit/banks')->assertUnauthorized();
});

it('refuses a Member viewing the audit', function () {
    ['user' => $member] = appVendorDocsTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/vendor-docs/audit/banks')->assertForbidden();
});

it('lists no banks when none are Plaid-connected', function () {
    ['user' => $admin] = appVendorDocsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/vendor-docs/audit/banks')->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('summarizes an audit period with zero coverage checks when there are none', function () {
    ['user' => $admin] = appVendorDocsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/vendor-docs/audit?'.http_build_query([
        'start_date' => '2026-01-01',
        'end_date' => '2026-06-30',
        'type' => 'general',
        'bank_account_ids' => [],
    ]))->assertOk();

    expect($response->json('data.not_found_policies'))->toBe([])
        ->and($response->json('data.can_download'))->toBeTrue();
});
