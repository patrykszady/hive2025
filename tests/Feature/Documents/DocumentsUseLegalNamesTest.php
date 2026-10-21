<?php

use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\Estimate;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Services\LienWaivers\LienWaiverPayerDefaults;
use App\Support\EstimateDocumentGenerator;
use App\Support\SwornStatementGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Documents a client signs or keeps carry the legal first name, never the
 * nickname the app shows on screen (estimate 273: Zachary "Zach" Wong,
 * 2026-10-21).
 */
beforeEach(function (): void {
    $this->gc = Vendor::query()->create(['business_name' => 'GS Construction & Remodeling, Inc', 'business_type' => 'Sub']);

    $admin = User::query()->create([
        'first_name' => 'Test', 'last_name' => 'Owner',
        'email' => 'legal-name-'.Str::random(8).'@example.test',
        'cell_phone' => '7'.random_int(100000000, 999999999),
        'password' => bcrypt('password'),
    ]);
    $admin->forceFill(['primary_vendor_id' => $this->gc->id])->saveQuietly();
    $admin->vendors()->attach($this->gc->id, ['role_id' => 1, 'is_employed' => true]);
    $this->actingAs($admin->fresh());

    $client = Client::query()->create(['address' => '1 Basement Way', 'city' => 'Glenview', 'state' => 'IL', 'zip_code' => '60025']);
    $zach = User::query()->create([
        'first_name' => 'Zachary', 'nickname' => 'Zach', 'last_name' => 'Wong',
        'email' => 'zach-'.Str::random(8).'@example.test',
        'cell_phone' => '7'.random_int(100000000, 999999999),
        'password' => bcrypt('password'),
    ]);
    $client->users()->attach($zach->id);
    $client->vendors()->attach($this->gc->id);

    $this->project = Project::query()->create([
        'project_name' => 'Basement', 'belongs_to_vendor_id' => $this->gc->id, 'client_id' => $client->id,
        'address' => '1 Basement Way', 'city' => 'Glenview', 'state' => 'IL', 'zip_code' => '60025',
    ]);
});

it('fills a contract\'s client name with the legal name', function () {
    $template = EmailTemplate::withoutGlobalScopes()->create([
        'vendor_id' => $this->gc->id, 'name' => 'Contract', 'type' => 'contract', 'subject' => null,
        'body' => '<p>This agreement is with {{client_name}}.</p>',
    ]);
    $estimate = Estimate::withoutGlobalScopes()->create([
        'project_id' => $this->project->id, 'belongs_to_vendor_id' => $this->gc->id,
        'options' => ['contract_template_ids' => [$template->id]],
    ]);

    $body = implode(' ', EstimateDocumentGenerator::contractBodiesForEstimate($estimate, config('app.timezone')));

    expect($body)->toContain('This agreement is with Zachary Wong.')
        ->and($body)->not->toContain('Zach Wong');
});

it('names the owner by legal name on lien waivers and sworn statements', function () {
    $context = SwornStatementGenerator::buildContext($this->project, $this->gc, [], 0.0);

    expect(app(LienWaiverPayerDefaults::class)->forProject($this->project)['name'])->toBe('Zachary Wong')
        ->and($context['view']['ownerName'])->toBe('Zachary Wong')
        ->and($context['filename'])->toContain('zachary-wong')
        ->and($context['filename'])->not->toContain('zach-wong');
});

it('keeps the nickname on screen', function () {
    expect($this->project->client->name)->toBe('Zach Wong')
        ->and($this->project->client->legal_name)->toBe('Zachary Wong');
});
