<?php

use App\Models\Vendor;
use App\Models\VendorDoc;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports identical entries, overlapping terms of one policy, a number copied across rows, and odd terms — not renewals or shared certificates', function () {
    $v = Vendor::factory()->create(['business_name' => 'RG Tile, Inc']);
    $mk = fn (string $type, string $number, string $from, string $to, string $file) => VendorDoc::withoutEvents(fn () => VendorDoc::create([
        'type' => $type, 'vendor_id' => $v->id, 'belongs_to_vendor_id' => 1, 'number' => $number, 'doc_filename' => $file, 'effective_date' => $from, 'expiration_date' => $to,
    ]));
    // normal: renewals keep the number; one certificate backs GL and WC
    $mk('general', 'BLS60689557', '2023-12-17', '2024-12-17', 'a.pdf');
    $mk('workers', 'TARIL11646-15', '2023-12-17', '2024-12-17', 'a.pdf');
    $mk('general', 'BLS60689557', '2024-12-17', '2025-12-17', 'b.pdf');
    // identical, the number spaced differently
    $mk('workers', 'TARIL11646-16', '2024-03-14', '2025-03-14', 'c.pdf');
    $mk('workers', 'TARIL 11646-16', '2024-03-14', '2025-03-14', 'd.pdf');
    // identical, the number missing its leading letters on one row
    $mk('workers', 'AFWCP100134391', '2025-10-07', '2026-10-07', 'i.pdf');
    $mk('workers', '100134391', '2025-10-07', '2026-10-07', 'j.pdf');
    // overlap
    $mk('workers', '93-N6-Z171-8', '2024-04-25', '2026-04-25', 'e.pdf');
    $mk('workers', '93-N6-Z171-8', '2024-03-26', '2026-03-26', 'f.pdf');
    // copied
    $mk('general', '93-LK-V699-3', '2023-06-02', '2024-06-02', 'g.pdf');
    $mk('workers', '93-LK-V699-3', '2023-06-02', '2024-06-02', 'g.pdf');
    // odd term
    $mk('workers', 'WC5-39S-352647-010', '2020-10-22', '2021-01-22', 'h.pdf');

    $findings = \App\Console\Commands\VendorDocDuplicates::findings(VendorDoc::withoutGlobalScopes()->with('vendor')->get());
    expect($findings->countBy('kind')->all())->toBe(['identical' => 2, 'overlap' => 1, 'copied' => 1, 'odd-term' => 1])
        ->and($findings->where('kind', 'identical')->pluck('note')->all())->toEqualCanonicalizing(['TARIL11646-16: the same term entered twice', 'AFWCP100134391: the same term entered twice'])
        ->and($findings->firstWhere('kind', 'overlap')['note'])->toBe('93-N6-Z171-8: the same policy with overlapping, different terms')
        ->and($findings->firstWhere('kind', 'copied')['note'])->toBe('93-LK-V699-3 on both the general and workers rows of one certificate')
        ->and($findings->firstWhere('kind', 'odd-term')['note'])->toBe('WC5-39S-352647-010: a 92-day term');

    $this->artisan('vendor-docs:duplicates', ['--vendor' => $v->id])
        ->expectsOutputToContain('[identical] TARIL11646-16: the same term entered twice')
        ->expectsOutputToContain('5 finding(s): 2 identical, 1 overlap, 1 copied, 1 odd-term.')
        ->assertExitCode(0);
});
