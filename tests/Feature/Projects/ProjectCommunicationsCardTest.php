<?php

/**
 * The project page's Communications card: a project's calls, texts and
 * emails with its stakeholders, merged newest-first (ProjectCommunications
 * wraps ProjectCommunicationsTimeline), filterable by kind, paginated with
 * "Load more", and an email row opens a detail modal. Same visibility as
 * the Email Tracking card: the owning vendor's Admins only.
 */

use App\Livewire\Projects\ProjectCommunications;
use App\Livewire\Projects\ProjectShow;
use App\Models\CallLog;
use App\Models\EmailMessage;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/t3-fixtures.php';
require_once __DIR__.'/../../Support/service-call-fixtures.php';

it('shows a project\'s calls, texts and emails newest first', function () {
    $world = serviceCallWorld();
    $digits = substr(preg_replace('/\D/', '', $world['katie']->cell_phone), -10);

    $call = CallLog::withoutEvents(fn () => CallLog::create([
        'direction' => 'incoming', 'from_number' => '+1'.$digits, 'to_number' => '+12247354200', 'status' => 'completed', 'duration_seconds' => 95,
    ]));
    $call->forceFill(['created_at' => now()->subHours(3)])->saveQuietly();

    $thread = SmsGroupThread::withoutEvents(fn () => SmsGroupThread::create(['client_id' => $world['client']->id, 'participants' => ['+1'.$digits], 'from_number' => '+12247354200']));
    $text = SmsMessage::withoutEvents(fn () => SmsMessage::create(['thread_id' => $thread->id, 'direction' => 'inbound', 'from_number' => '+1'.$digits, 'text' => 'Thursday works!']));
    $text->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();

    // Gave this test email its own project_id — EmailMessage::forProject()
    // only infers an unplaced email from client_id while summarized_at is
    // still null, and this test isn't exercising that pending-AI-read path.
    $email = EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'client_id' => $world['client']->id,
        'project_id' => $world['project']->id,
        'subject' => 'Grout',
        'summary' => 'Katie reports cracked grout.',
        'sent_at' => now()->subHour(),
        'attachments' => [['path' => 'emails/x.jpg', 'name' => 'x.jpg', 'mime' => 'image/jpeg', 'size' => 1]],
    ]);

    $component = Livewire::actingAs($world['admin'])
        ->test(ProjectCommunications::class, ['project' => $world['project']])
        ->assertCount('items', 3);

    $items = $component->get('items');
    expect(collect($items)->map(fn ($item) => $item['kind'].':'.$item['id'])->all())
        ->toBe(["email:{$email->id}", "sms:{$text->id}", "call:{$call->id}"])
        ->and($items[0]['preview'])->toBe('Katie reports cracked grout.')
        ->and($items[0]['attachment_count'])->toBe(1)
        ->and($items[1]['who'])->toBe('Katie Love')
        ->and($component->get('nextBefore'))->toBeNull();

    $component->assertSee('Grout')->assertSee('Thursday works!');
});

it('filters the Communications card by kind', function () {
    $world = serviceCallWorld();
    $digits = substr(preg_replace('/\D/', '', $world['katie']->cell_phone), -10);

    CallLog::withoutEvents(fn () => CallLog::create([
        'direction' => 'incoming', 'from_number' => '+1'.$digits, 'to_number' => '+12247354200', 'status' => 'completed', 'duration_seconds' => 10,
    ]));
    $thread = SmsGroupThread::withoutEvents(fn () => SmsGroupThread::create(['client_id' => $world['client']->id, 'participants' => ['+1'.$digits], 'from_number' => '+12247354200']));
    SmsMessage::withoutEvents(fn () => SmsMessage::create(['thread_id' => $thread->id, 'direction' => 'inbound', 'from_number' => '+1'.$digits, 'text' => 'Hi']));
    EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'project_id' => $world['project']->id,
        'subject' => 'Cabinet hardware',
    ]);

    $component = Livewire::actingAs($world['admin'])
        ->test(ProjectCommunications::class, ['project' => $world['project']])
        ->assertCount('items', 3);

    $component->set('kind', 'call')->assertCount('items', 1);
    expect(collect($component->get('items'))->pluck('kind')->unique()->all())->toBe(['call']);

    $component->set('kind', 'sms')->assertCount('items', 1);
    expect(collect($component->get('items'))->pluck('kind')->unique()->all())->toBe(['sms']);

    $component->set('kind', 'email')->assertCount('items', 1);
    expect(collect($component->get('items'))->pluck('kind')->unique()->all())->toBe(['email']);

    $component->set('kind', 'all')->assertCount('items', 3);
});

it('opens on the latest five communications and loads ten more at a time', function () {
    $world = serviceCallWorld();
    $digits = substr(preg_replace('/\D/', '', $world['katie']->cell_phone), -10);

    foreach (range(1, 17) as $i) {
        $call = CallLog::withoutEvents(fn () => CallLog::create([
            'direction' => 'incoming', 'from_number' => '+1'.$digits, 'to_number' => '+12247354200', 'status' => 'completed', 'duration_seconds' => 10,
        ]));
        $call->forceFill(['created_at' => now()->subMinutes($i)])->saveQuietly();
    }

    // A short card: the latest five, so Communications doesn't take over the project page.
    $component = Livewire::actingAs($world['admin'])
        ->test(ProjectCommunications::class, ['project' => $world['project']])
        ->assertCount('items', 5)
        ->assertSee('Load more');

    expect($component->get('nextBefore'))->not->toBeNull();

    $component->call('loadMore')->assertCount('items', 15);
    expect($component->get('nextBefore'))->not->toBeNull();

    $component->call('loadMore')->assertCount('items', 17);
    expect($component->get('nextBefore'))->toBeNull();
});

it('opens an email\'s AI summary, latest body and a signed attachment link in the modal', function () {
    $world = serviceCallWorld();

    $email = EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $world['vendor']->id,
        'client_id' => $world['client']->id,
        'project_id' => $world['project']->id,
        'from_email' => $world['katie']->email,
        'from_name' => 'Katie Love',
        'subject' => 'Cabinet hardware',
        'summary' => 'Katie asks for kitchen cabinet hardware options by Friday.',
        'action_items' => ['Patryk: Send the kitchen hardware options by Friday'],
        'body_text' => "Can you send the hardware options by Friday?\n\nOn Mon, Oct 12, 2026 at 9:00 AM Patryk wrote:\n> Here is the schedule.",
        'attachments' => [['path' => 'emails/hardware.jpg', 'name' => 'hardware.jpg', 'mime' => 'image/jpeg', 'size' => 2048]],
    ]);

    $component = Livewire::actingAs($world['admin'])
        ->test(ProjectCommunications::class, ['project' => $world['project']])
        ->call('selectEmail', $email->id)
        ->assertSet('openEmailId', $email->id)
        ->assertSet('showEmailModal', true)
        ->assertSee('Katie asks for kitchen cabinet hardware options by Friday.')
        ->assertSee('Patryk: Send the kitchen hardware options by Friday')
        ->assertSee('Can you send the hardware options by Friday?')
        // The quoted history sits behind a toggle (hidden client-side via
        // Alpine), but it's still in the server-rendered HTML.
        ->assertSee('Here is the schedule.');

    $html = $component->html();
    expect($html)->toContain('/emails/'.$email->id.'/attachments/0/signed')
        ->and($html)->toContain('signature=');
});

it('never opens an email belonging to a different project or vendor in the modal', function () {
    $world = serviceCallWorld();

    // A second, unrelated vendor/client/project — built directly rather than
    // via serviceCallWorld() again, which would collide on that helper's
    // hardcoded CompanyEmail grant_id.
    $otherVendor = sec3_makeVendor('Other Co');
    $otherClient = sec3_makeClient($otherVendor);
    $otherProject = sec3_makeProject($otherVendor, $otherClient);

    $foreignEmail = EmailMessage::factory()->create([
        'belongs_to_vendor_id' => $otherVendor->id,
        'project_id' => $otherProject->id,
        'subject' => 'Someone else\'s inbox',
    ]);

    Livewire::actingAs($world['admin'])
        ->test(ProjectCommunications::class, ['project' => $world['project']])
        ->call('selectEmail', $foreignEmail->id)
        ->assertSet('openEmailId', null)
        ->assertSet('showEmailModal', false)
        ->assertDontSee('Someone else\'s inbox');
});

it('refuses a client user the Communications card', function () {
    $world = serviceCallWorld();

    Livewire::actingAs($world['katie'])
        ->test(ProjectCommunications::class, ['project' => $world['project']])
        ->assertForbidden();
});

it('refuses another vendor\'s admin the Communications card', function () {
    $world = serviceCallWorld();
    $vendorB = sec3_makeVendor('Other Co');
    $adminB = sec3_makeAdmin($vendorB, 'otheradmin');

    Livewire::actingAs($adminB)
        ->test(ProjectCommunications::class, ['project' => $world['project']])
        ->assertForbidden();
});

it('wires the Communications card into the project page for an admin but not a client', function () {
    $world = serviceCallWorld();

    Livewire::actingAs($world['admin'])
        ->test(ProjectShow::class, ['project' => $world['project']])
        ->assertOk()
        ->assertSeeHtml('Communications');

    Livewire::actingAs($world['katie'])
        ->test(ProjectShow::class, ['project' => $world['project']])
        ->assertOk()
        ->assertDontSeeHtml('Communications');
});
