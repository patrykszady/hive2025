<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

function reimbursementProject(): Project
{
    $vendor = Vendor::query()->create([
        'business_name' => 'GS Construction', 'business_type' => 'Sub', 'business_email' => 'gc@example.test',
        'address' => '123 Main St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);
    $user = \App\Models\User::query()->create([
        'first_name' => 'Pdf', 'last_name' => 'Tester', 'email' => 'reimb-pdf@example.test',
        'cell_phone' => '7005550103', 'password' => bcrypt('password'),
    ]);
    $user->forceFill(['primary_vendor_id' => $vendor->id])->saveQuietly();
    test()->actingAs($user);

    $project = Project::query()->create([
        'project_name' => 'Family Room',
        'client_id' => Client::query()->create(['business_name' => 'Owner', 'address' => '1 Oak St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601'])->id,
        'address' => '1 Oak St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);
    $project->forceFill(['belongs_to_vendor_id' => $vendor->id])->saveQuietly();

    return $project;
}

it('refuses the reimbursements PDF without a valid signature', function () {
    $project = reimbursementProject();

    $this->get(route('projects.reimbursements.pdf', ['project' => $project->id]))->assertForbidden();
    $this->get(URL::signedRoute('projects.reimbursements.pdf', ['project' => $project->id]).'&tampered=1')->assertForbidden();
});

it('has nothing to download for a project without reimbursements', function () {
    $project = reimbursementProject();

    $this->get(URL::signedRoute('projects.reimbursements.pdf', ['project' => $project->id]))->assertNotFound();
});

it('prints a download link beside Reimbursements when given a URL', function () {
    $project = reimbursementProject();
    $finances = ['estimate' => 6297, 'change_orders' => 0, 'reimbursments' => 781.53, 'total_project' => 7078.53, 'payments' => 0, 'balance' => 7078.53];

    $html = Blade::render('<x-client-finances :project="$project" :finances="$finances" reimbursement-download-url="https://hive.contractors/projects/1/reimbursements.pdf?signature=abc" />', compact('project', 'finances'));

    expect($html)->toContain('href="https://hive.contractors/projects/1/reimbursements.pdf?signature=abc"')
        ->and($html)->toContain('Download')
        ->and($html)->not->toContain('wire:click');

    // No URL and no action: the plain row, as on the client portal.
    $plain = Blade::render('<x-client-finances :project="$project" :finances="$finances" />', compact('project', 'finances'));
    expect($plain)->not->toContain('Download');
});

it('strips a page break from the edge of a contract template but not from its middle', function () {
    $trailing = '<p>Last clause.</p><div style="page-break-before: always;"></div>';
    $middle = '<p>Part one.</p><div style="page-break-before: always;"></div><p>Part two.</p>';
    $leading = "\n<p>&nbsp;</p><div style=\"page-break-before: always;\"></div><p>Body.</p><br>";

    expect(\App\Support\EstimateDocumentGenerator::stripEdgePageBreaks($trailing))->toBe('<p>Last clause.</p>')
        ->and(\App\Support\EstimateDocumentGenerator::stripEdgePageBreaks($middle))->toBe($middle)
        ->and(\App\Support\EstimateDocumentGenerator::stripEdgePageBreaks($leading))->toBe('<p>Body.</p>');
});
