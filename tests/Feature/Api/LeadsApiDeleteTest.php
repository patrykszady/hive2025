<?php

use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/lead-intake-fixtures.php';

it('removes a lead the site withdrew, and only this company\'s own', function () {
    $fx = emailIntakeFixture();
    Queue::fake();
    Http::fake(['*' => Http::response([], 200)]);

    $id = $this->postJson('/api/v1/leads', emailLeadPayload(['phone' => '8322571204', 'attachments' => []]))->assertCreated()->json('data.id');
    $lead = Lead::withoutGlobalScopes()->findOrFail($id);
    $contact = User::find($lead->user_id);
    expect($contact)->not->toBeNull();

    // Another company's lead is not ours to remove.
    $other = Vendor::factory()->create();
    $theirs = Lead::create(['date' => now(), 'origin' => 'Email', 'belongs_to_vendor_id' => $other->id, 'created_by_user_id' => $fx['api']->id, 'lead_data' => ['name' => 'Someone Else']]);
    $this->deleteJson("/api/v1/leads/{$theirs->id}")->assertNotFound();
    expect($theirs->fresh()->deleted_at)->toBeNull();

    $this->deleteJson("/api/v1/leads/{$id}")->assertOk()->assertJson(['deleted' => true]);
    expect(Lead::withoutGlobalScopes()->find($id)->deleted_at)->not->toBeNull()
        // A contact that existed only for this lead goes with it.
        ->and(User::find($contact->id))->toBeNull();

    // Already gone.
    $this->deleteJson("/api/v1/leads/{$id}")->assertNotFound();
});
