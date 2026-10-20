<?php

use App\Models\EmailTemplate;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/** @return array{vendor: Vendor, user: User} */
function appEmailTemplatesTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

it('needs a token to list templates', function () {
    $this->getJson('/api/app/v1/email-templates')->assertUnauthorized();
});

it('refuses a Member listing templates, like the web', function () {
    ['user' => $member] = appEmailTemplatesTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/email-templates')->assertForbidden();
});

it('lists the estimate tab including invoice templates, like EmailTemplateIndex', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEmailTemplatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $estimate = EmailTemplate::create(['vendor_id' => $vendor->id, 'name' => 'Estimate A', 'type' => 'estimate', 'subject' => 'Your estimate', 'body' => 'Hi']);
    $invoice = EmailTemplate::create(['vendor_id' => $vendor->id, 'name' => 'Invoice A', 'type' => 'invoice', 'subject' => 'Your invoice', 'body' => 'Hi']);
    $contract = EmailTemplate::create(['vendor_id' => $vendor->id, 'name' => 'Contract A', 'type' => 'contract', 'subject' => null, 'body' => 'Hi']);

    $response = $this->getJson('/api/app/v1/email-templates?type=estimate')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($estimate->id, $invoice->id)
        ->and($ids)->not->toContain($contract->id);
});

it('creates a template, requiring a subject unless it is a contract', function () {
    ['user' => $admin] = appEmailTemplatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/email-templates', [
        'name' => 'No Subject',
        'body' => 'Hi {{client_name}}',
        'type' => 'estimate',
    ])->assertStatus(422)->assertJsonValidationErrors(['subject']);

    $response = $this->postJson('/api/app/v1/email-templates', [
        'name' => 'Contract Template',
        'body' => 'Hi {{client_name}}',
        'type' => 'contract',
    ])->assertCreated();

    expect($response->json('data.type'))->toBe('contract')
        ->and($response->json('data.placeholders'))->toContain('estimate_total_words');
});

it('refuses a Member creating a template, like the web', function () {
    ['user' => $member] = appEmailTemplatesTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->postJson('/api/app/v1/email-templates', [
        'name' => 'Nope', 'body' => 'Hi', 'type' => 'estimate', 'subject' => 'Hi',
    ])->assertForbidden();
});

it('updates and deletes a template owned by the signed-in company', function () {
    ['vendor' => $vendor, 'user' => $admin] = appEmailTemplatesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $template = EmailTemplate::create(['vendor_id' => $vendor->id, 'name' => 'Old', 'type' => 'lead', 'subject' => null, 'body' => 'Hi']);

    $this->putJson("/api/app/v1/email-templates/{$template->id}", [
        'name' => 'New Name', 'body' => 'Updated body', 'type' => 'lead', 'subject' => 'A new lead',
    ])->assertOk()->assertJsonPath('data.name', 'New Name');

    $this->deleteJson("/api/app/v1/email-templates/{$template->id}")->assertOk();

    expect(EmailTemplate::withoutGlobalScopes()->find($template->id))->toBeNull();
});

it('refuses updating another company\'s template, like the web', function () {
    ['user' => $admin] = appEmailTemplatesTeamUser(role: 1);
    $otherVendor = Vendor::factory()->create();
    $foreign = EmailTemplate::create(['vendor_id' => $otherVendor->id, 'name' => 'Foreign', 'type' => 'lead', 'subject' => null, 'body' => 'Hi']);

    Sanctum::actingAs($admin, ['app']);

    // EmailTemplate's global "vendor" scope already hides rows outside the
    // signed-in company's tree — so this 404s rather than 403ing, same as
    // a plain findOrFail() would for any other tenant's row.
    $this->putJson("/api/app/v1/email-templates/{$foreign->id}", [
        'name' => 'Hijacked', 'body' => 'x', 'type' => 'lead', 'subject' => null,
    ])->assertNotFound();
});
