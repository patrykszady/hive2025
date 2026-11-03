<?php

use App\Http\Controllers\CompanyEmailController;
use App\Models\CompanyEmail;
use App\Models\Receipt;
use App\Models\ReceiptAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\NylasService;
use App\Services\Receipts\ReceiptPatternDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The City of Rolling Meadows emails permit-fee receipts as a PDF from
 * noreply@cityrm.org ("A copy of your receipt has been attached", 2026-11-02).
 * A receipt rule only reaches a mailbox through the company's receipt account
 * for its vendor, and the /receipts screen never created one — so a rule
 * saved there for a new vendor was silently never used.
 */

beforeEach(function (): void {
    $this->company = Vendor::factory()->create(['business_name' => 'GS Test Construction']);
    $this->user = User::factory()->create(['primary_vendor_id' => $this->company->id]);
    $this->company->users()->attach($this->user->id, ['role_id' => 1]);
    $this->city = Vendor::factory()->create(['business_name' => 'City Of Rolling Meadows', 'business_type' => 'Retail']);
    $this->company->vendors()->attach($this->city->id);
    $this->mailbox = CompanyEmail::withoutGlobalScopes()->create(['vendor_id' => $this->company->id, 'email' => 'patryk@example.test', 'grant_id' => 'grant-1', 'api_json' => []]);
});

function rollingMeadowsRule(): array
{
    return [
        'vendor_id' => test()->city->id,
        'from_address' => 'noreply@cityrm.org',
        'from_subjects' => ['A copy of your receipt has been attached'],
        'receipt_type' => 1,
        'options' => ['pdf_html' => true, 'document_model' => 'prebuilt-receipt'],
    ];
}

it('links a new rule\'s vendor to the company, so the mailbox uses the rule', function () {
    $this->actingAs($this->user);
    expect($this->mailbox->receipts()->count())->toBe(0);

    $rule = app(ReceiptPatternDirectory::class)->create(rollingMeadowsRule());

    expect(ReceiptAccount::withoutGlobalScopes()->where('vendor_id', $this->city->id)->where('belongs_to_vendor_id', $this->company->id)->count())->toBe(1)
        ->and($this->mailbox->fresh()->receipts()->pluck('receipts.id')->all())->toBe([$rule->id]);

    // Editing it, or adding a second rule for the same vendor, never links twice.
    app(ReceiptPatternDirectory::class)->update($rule, rollingMeadowsRule());
    app(ReceiptPatternDirectory::class)->create(['from_address' => 'billing@cityrm.org'] + rollingMeadowsRule());
    expect(ReceiptAccount::withoutGlobalScopes()->where('vendor_id', $this->city->id)->count())->toBe(1);
});

it('matches the city\'s receipt email, and not its permit-issued notices', function () {
    $this->actingAs($this->user);
    $rule = app(ReceiptPatternDirectory::class)->create(rollingMeadowsRule());

    $controller = new class(Mockery::mock(NylasService::class)) extends CompanyEmailController
    {
        public function match(string $from, string $subject, ?array $message = null): ?Receipt
        {
            return $this->findMatchingReceipt($from, $subject, $message);
        }
    };
    // The city's PDF arrives typed as a generic file, not application/pdf.
    $receiptEmail = ['attachments' => [['filename' => 'Receipt_02-11-2026_12-20-47.pdf', 'content_type' => 'application/octet-stream', 'is_inline' => false]]];

    expect($controller->match('noreply@cityrm.org', 'A copy of your receipt has been attached', $receiptEmail)?->id)->toBe($rule->id)
        ->and($controller->match('cd@cityrm.org', 'City of Rolling Meadows Building Permit has been Issued', $receiptEmail))->toBeNull()
        ->and($controller->match('browderc@cityrm.org', 'Permit is ready for 123 Villa Circle', ['attachments' => []]))->toBeNull();
});
