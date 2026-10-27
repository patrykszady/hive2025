<?php

use App\Models\Client;
use App\Models\Estimate;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Support\EstimateDocumentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * The printed estimate (2026-10-27, Patryk on estimate 273): "there's always
 * an empty page" — a forced page break before the Payments / Finances grid,
 * printed even when both were empty — "and the Total Estimate … to the
 * right". Read through EstimateDocumentGenerator::renderHtml(), the HTML
 * Chrome prints, so no browser is needed.
 */

/** @return array{vendor: Vendor, project: Project, estimate: Estimate, user: User} */
function pdfLayoutWorld(int $statusCode): array
{
    config(['scout.driver' => null]);
    $vendor = Vendor::query()->create(['business_name' => 'GS Construction & Remodeling, Inc', 'business_type' => 'GC', 'business_phone' => '2247354200', 'business_email' => 'crew@gs.construction']);
    $patryk = User::query()->create(['first_name' => 'Patryk', 'last_name' => 'Szady', 'email' => 'p.'.Str::random(5).'@gs.construction', 'cell_phone' => '2245559111', 'password' => bcrypt('x'), 'primary_vendor_id' => $vendor->id, 'registration' => ['registered' => true]]);
    $vendor->users()->attach($patryk->id, ['role_id' => 1, 'is_employed' => 1]);
    test()->actingAs($patryk);

    $client = Client::factory()->create(['address' => '123 Villa Circle Drive', 'city' => 'Palatine', 'state' => 'IL', 'zip_code' => '60067']);
    $client->vendors()->attach($vendor->id, ['source' => 'test']);
    $project = Project::query()->create(['project_name' => 'Basement', 'client_id' => $client->id, 'belongs_to_vendor_id' => $vendor->id, 'address' => '123 Villa Circle Drive', 'city' => 'Palatine', 'state' => 'IL', 'zip_code' => '60067']);
    ProjectStatus::create(['project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id, 'status_code' => $statusCode, 'start_date' => now()->addDays(3)]);
    $estimate = Estimate::withoutGlobalScopes()->create(['project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id, 'options' => ['contract_template_ids' => []]]);

    return ['vendor' => $vendor, 'project' => $project, 'estimate' => $estimate, 'user' => $patryk];
}

/** The status code whose title is $title, found rather than hard-coded. */
function statusCodeTitled(string $title): int
{
    return collect(range(0, 20))->first(fn (int $code) => (new ProjectStatus(['status_code' => $code]))->title === $title)
        ?? throw new RuntimeException("No status titled {$title}");
}

it('prints no page break before an empty Payments / Finances grid, and sets the totals flush right', function () {
    ['estimate' => $estimate] = pdfLayoutWorld(statusCodeTitled('Scheduled'));

    $html = EstimateDocumentGenerator::renderHtml($estimate, 'Estimate')['html'];

    expect(substr_count($html, 'page-break-before: always'))->toBe(0, 'no payments, no finances, no contract: nothing earns a page of its own')
        ->and($html)->not->toContain('<flux:heading size="lg">Payments')
        ->and($html)->toContain('data-estimate-totals')
        ->and($html)->toMatch('/<div class="flex justify-end" data-estimate-totals>\s*<table/')
        ->and($html)->toContain('Total Estimate');
});

it('still gives Payments their own page once there are any', function () {
    ['vendor' => $vendor, 'project' => $project, 'estimate' => $estimate, 'user' => $user] = pdfLayoutWorld(statusCodeTitled('Scheduled'));
    Payment::query()->create(['project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id, 'amount' => 2500, 'date' => now()->toDateString(), 'reference' => 'Deposit', 'created_by_user_id' => $user->id]);

    $html = EstimateDocumentGenerator::renderHtml($estimate, 'Estimate')['html'];

    expect(substr_count($html, 'page-break-before: always'))->toBe(1)
        ->and($html)->toContain('Payments')
        ->and($html)->toContain('Deposit');
});

it('gives Finances their own page on an active job, where the totals block is not printed', function () {
    ['estimate' => $estimate] = pdfLayoutWorld(statusCodeTitled('Active'));

    $html = EstimateDocumentGenerator::renderHtml($estimate, 'Estimate')['html'];

    expect(substr_count($html, 'page-break-before: always'))->toBe(1)
        ->and($html)->toContain('Estimate Finances')
        ->and($html)->not->toContain('data-estimate-totals');
});

it('prints the contractor card half-width, without the Short Name row', function () {
    ['vendor' => $vendor, 'estimate' => $estimate] = pdfLayoutWorld(statusCodeTitled('Scheduled'));
    $vendor->forceFill(['options' => (object) ['short_name' => 'GS Construction']])->save();

    $html = EstimateDocumentGenerator::renderHtml($estimate, 'Estimate')['html'];

    expect($html)->toContain('Contractor Details')
        ->and($html)->toMatch('/GS Construction (&|&amp;) Remodeling, Inc/i')
        ->and($html)->not->toContain('Short Name')
        ->and($html)->toContain('<div class="grid grid-cols-2 gap-4 items-start">')
        ->and($html)->not->toContain('class="grid grid-cols-5');
});

it('keeps the contract address on two lines, each line escaped', function () {
    $body = EstimateDocumentGenerator::renderContractTemplate('<p>{{project_address}}</p>', ['project_address' => '123 Villa Circle Drive<br>Palatine, IL 60067']);
    expect($body)->toBe('<p>123 Villa Circle Drive<br>Palatine, IL 60067</p>');

    // A hostile address still cannot inject markup; only the line break survives.
    $hostile = EstimateDocumentGenerator::renderContractTemplate('<p>{{project_address}}</p>', ['project_address' => '"><img src=x onerror=alert(1)><br><script>x</script>']);
    expect($hostile)->toBe('<p>&quot;&gt;&lt;img src=x onerror=alert(1)&gt;<br>&lt;script&gt;x&lt;/script&gt;</p>');

    // And the real estimate's contract reads the same way.
    ['vendor' => $vendor, 'estimate' => $estimate] = pdfLayoutWorld(statusCodeTitled('Scheduled'));
    $template = \App\Models\EmailTemplate::withoutGlobalScopes()->create(['vendor_id' => $vendor->id, 'name' => 'Contract', 'type' => 'contract', 'subject' => null, 'body' => '<p>Property: {{project_address}}</p>']);
    $estimate->update(['options' => ['contract_template_ids' => [$template->id]]]);
    $html = EstimateDocumentGenerator::renderHtml($estimate, 'Estimate')['html'];
    expect($html)->toContain('Property: 123 Villa Circle Drive<br>Palatine, IL 60067')
        ->and($html)->not->toContain('&lt;br&gt;');
});
