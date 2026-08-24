<?php

use App\Livewire\Projects\EmailTrackingTable;
use App\Models\EmailTracking;
use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The thread-status rule for 'replied': it is the thread's main status only
 * while the reply is the LATEST word. Once we write back on the same thread,
 * the newer send's chain tells the truth again.
 */
function trackingWorld(): array
{
    $vendor = Vendor::factory()->create();
    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $lead = Lead::create([
        'date' => now(),
        'origin' => 'gs.construction',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $admin->id,
        'lead_data' => ['name' => 'Client', 'email' => 'client@example.com'],
    ]);

    return [$vendor, $admin, $lead];
}

function threadEvent(array $attrs): EmailTracking
{
    return EmailTracking::withoutGlobalScopes()->create(array_merge([
        'thread_id' => 'nylas-thread-1',
        'message_id' => 'msg-1',
        'email_template_name' => 'lead-reply',
        'recipient_emails' => ['client@example.com'],
    ], $attrs));
}

function mainRow($admin, $lead)
{
    return Livewire::actingAs($admin)
        ->test(EmailTrackingTable::class, ['leadId' => $lead->id])
        ->instance()
        ->emailTrackingEvents()
        ->first();
}

it('shows Replied while the reply is the latest word on the thread', function () {
    [$vendor, $admin, $lead] = trackingWorld();

    threadEvent(['belongs_to_vendor_id' => $vendor->id, 'lead_id' => $lead->id,
        'event_type' => 'sent', 'event_at' => now()->subHours(3)]);
    threadEvent(['belongs_to_vendor_id' => $vendor->id, 'lead_id' => $lead->id,
        'event_type' => 'replied', 'event_at' => now()->subHour()]);

    expect(mainRow($admin, $lead)->event_type)->toBe('replied');
});

it('drops back to the newer send once we write back on the same thread', function () {
    [$vendor, $admin, $lead] = trackingWorld();

    threadEvent(['belongs_to_vendor_id' => $vendor->id, 'lead_id' => $lead->id,
        'event_type' => 'sent', 'event_at' => now()->subHours(5)]);
    threadEvent(['belongs_to_vendor_id' => $vendor->id, 'lead_id' => $lead->id,
        'event_type' => 'replied', 'event_at' => now()->subHours(3)]);
    // Our follow-up in the SAME Nylas thread.
    threadEvent(['belongs_to_vendor_id' => $vendor->id, 'lead_id' => $lead->id,
        'event_type' => 'sent', 'event_at' => now()->subHour()]);

    expect(mainRow($admin, $lead)->event_type)->not->toBe('replied');
});
