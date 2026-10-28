<?php

use App\Jobs\SendGroupMms;
use App\Jobs\SendOutboundSmsBrowserNotifications;
use App\Livewire\Sms\SmsConversation;
use App\Livewire\Sms\SmsIndex;
use App\Livewire\Sms\SmsThreadList;
use App\Livewire\Sms\TeamList;
use App\Models\AppNotification;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadRead;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Sms\ConversationPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Team threads (Patryk, 2026-10-27): Greg and Patryk — the whole team —
 * message each other inside hive.contractors, and those messages never go
 * out as texts. The Team tab in /messages (TeamList) opens the thread with a
 * teammate or the whole team; members alone see it; the other members get
 * Hive's push; the Messages tab lists texts only.
 */

/** @return array{gs: Vendor, patryk: User, greg: User, cezary: User} */
function teamWorld(): array
{
    config(['scout.driver' => null, 'services.telnyx.api_key' => 'test-api-key', 'services.telnyx.app_connection_id' => 'app-connection']);
    // Vendor 1 = GS: the only company whose people can call each other in Hive (TeamPhone::enabledFor).
    $gs = Vendor::query()->find(1) ?: Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction & Remodeling, Inc']);
    $gs->forceFill(['options' => (object) ['short_name' => 'GS Construction']])->save();
    $gs->users()->detach();
    $make = function (string $first, string $last, string $cell, ?string $nick = null) use ($gs) {
        $u = User::query()->create(['first_name' => $first, 'nickname' => $nick, 'last_name' => $last, 'email' => strtolower($first).'.'.uniqid().'@gs.construction', 'cell_phone' => $cell, 'password' => bcrypt('x'), 'primary_vendor_id' => $gs->id, 'registration' => ['registered' => true]]);
        $gs->users()->attach($u->id, ['role_id' => 1, 'is_employed' => 1]);

        return $u;
    };
    $patryk = $make('Patryk', 'Szady', '2249993880');
    $greg = $make('Grzegorz', 'Szady', '8478737217', 'Greg');
    $cezary = $make('Cezary', 'Kowal', '8475550123');
    $former = $make('Old', 'Hand', '8475550999');
    $gs->users()->updateExistingPivot($former->id, ['is_employed' => 0]);

    return compact('gs', 'patryk', 'greg', 'cezary');
}

it('lists the employed teammates, and opens one Hive-only thread per pair', function () {
    $w = teamWorld();
    $this->actingAs($w['patryk']);

    $list = Livewire::test(TeamList::class);
    expect($list->instance()->teammates->pluck('first_name')->all())->toBe(['Cezary', 'Grzegorz'])
        ->and(collect($list->instance()->rows)->pluck('title')->all())->toBe(['GS Construction Crew', 'Cezary', 'Greg'])
        ->and(collect($list->instance()->rows)->pluck('thread_id')->all())->toBe([null, null, null], 'nothing made until a row is opened');
    $list->assertSeeHtml('data-team-call="'.$w['greg']->id.'"');
    // Without the browser phone (another company, or Telnyx unset) the rows have no Call button.
    config(['services.telnyx.app_connection_id' => null]);
    Livewire::test(TeamList::class)->assertDontSeeHtml('data-team-call=');
    config(['services.telnyx.app_connection_id' => 'app-connection']);

    $list->call('openTeammate', $w['greg']->id)->assertDispatchedTo(SmsIndex::class, 'threadSelected');
    $thread = SmsGroupThread::query()->where('kind', SmsGroupThread::KIND_TEAM)->sole();
    expect($thread->memberIds())->toBe(collect([$w['patryk']->id, $w['greg']->id])->sort()->values()->all())
        ->and($thread->participants)->toBe(['+12249993880', '+18478737217'])
        ->and($thread->vendor_id)->toBe($w['gs']->id)
        ->and($thread->hasPendingOptIn())->toBeFalse()
        ->and($thread->teamTitleFor($w['patryk']))->toBe('Greg')
        ->and($thread->teamTitleFor($w['greg']))->toBe('Patryk');

    // Opening it again from either side finds the same thread.
    Livewire::test(TeamList::class)->call('openTeammate', $w['greg']->id);
    $this->actingAs($w['greg']);
    Livewire::test(TeamList::class)->call('openTeammate', $w['patryk']->id);
    expect(SmsGroupThread::query()->where('kind', SmsGroupThread::KIND_TEAM)->count())->toBe(1);

    // A stranger to the team cannot open one.
    $outsider = User::query()->create(['first_name' => 'Out', 'last_name' => 'Sider', 'email' => 'o.'.uniqid().'@example.test', 'cell_phone' => '2245550199', 'password' => bcrypt('x'), 'registration' => ['registered' => true]]);
    $this->actingAs($outsider);
    Livewire::test(TeamList::class)->call('openTeammate', $w['greg']->id)->assertForbidden();
});

it('shows the Team tab to company users only, and a thread link lands on the tab that lists it', function () {
    $w = teamWorld();
    Bus::fake();
    $team = SmsGroupThread::teamThreadFor([$w['patryk']->id, $w['greg']->id], $w['gs']->id);
    $text = SmsGroupThread::query()->create(['from_number' => '+12247354200', 'participants' => ['+18475551212'], 'vendor_id' => $w['gs']->id, 'last_activity_at' => now()]);
    $this->actingAs($w['patryk']);

    Livewire::test(SmsIndex::class)->assertSet('showTeam', true)->assertSeeHtml('data-team-tab')->assertSeeLivewire(TeamList::class);

    // ?threadId= of a team conversation (the push, the bell) opens under Team; a text under Messages.
    Livewire::withQueryParams(['threadId' => $team->id])->test(SmsIndex::class)->assertSet('activeTab', 'team');
    Livewire::withQueryParams(['threadId' => $text->id])->test(SmsIndex::class)->assertSet('activeTab', 'messages');
    Livewire::withQueryParams(['threadId' => $text->id, 'activeTab' => 'team'])->test(SmsIndex::class)->assertSet('activeTab', 'messages');

    // In-page switches pick the tab the same way.
    Livewire::test(SmsIndex::class)->call('switchToThread', $team->id)->assertSet('activeTab', 'team')->assertSet('threadId', $team->id)
        ->call('switchToThread', $text->id)->assertSet('activeTab', 'messages');

    // Greg has something unread from Patryk: the Team tab carries a dot.
    app(\App\Services\GroupSmsService::class)->sendToThread($team, 'Running late', [], $w['patryk']->id);
    $this->actingAs($w['greg']);
    Livewire::test(SmsIndex::class)->assertSeeHtml('data-team-tab-unread');
    $this->actingAs($w['patryk']);
    Livewire::test(SmsIndex::class)->assertDontSeeHtml('data-team-tab-unread');
});

it('sends a team message inside Hive only: no Telnyx, no signature, a push to the other member', function () {
    $w = teamWorld();
    Bus::fake([SendGroupMms::class, SendOutboundSmsBrowserNotifications::class]);
    Http::fake();
    $thread = SmsGroupThread::teamThreadFor([$w['patryk']->id, $w['greg']->id], $w['gs']->id);
    $this->actingAs($w['patryk']);

    Livewire::test(SmsConversation::class, ['threadId' => $thread->id])
        ->set('newMessage', 'Running 10 min late to Violet')
        ->call('sendMessage')
        ->assertHasNoErrors();

    $message = SmsMessage::query()->where('thread_id', $thread->id)->sole();
    expect($message->text)->toBe('Running 10 min late to Violet')
        ->and($message->provider)->toBe('hive')
        ->and($message->status)->toBe('sent')
        ->and($message->sent_by_user_id)->toBe($w['patryk']->id)
        ->and($message->from_number)->toBe('+12249993880');
    Bus::assertNotDispatched(SendGroupMms::class);
    Http::assertNothingSent();
    Bus::assertDispatched(SendOutboundSmsBrowserNotifications::class, fn ($job) => $job->messageId === $message->id && $job->sentByUserId === $w['patryk']->id);

    // The push job writes a notification for the other member only, titled by the sender.
    (new SendOutboundSmsBrowserNotifications($message->id, $w['patryk']->id))->handle(app(\App\Services\WebPushService::class));
    expect(AppNotification::query()->where('type', 'team_message')->pluck('user_id')->all())->toBe([$w['greg']->id])
        ->and(AppNotification::query()->where('type', 'team_message')->value('title'))->toBe('Patryk');
});

it('shows each member their own messages on the right and the other\'s on the left, under the other\'s name', function () {
    $w = teamWorld();
    Bus::fake();
    $thread = SmsGroupThread::teamThreadFor([$w['patryk']->id, $w['greg']->id], $w['gs']->id);
    app(\App\Services\GroupSmsService::class)->sendToThread($thread, 'Running late', [], $w['patryk']->id);
    app(\App\Services\GroupSmsService::class)->sendToThread($thread, 'No problem', [], $w['greg']->id);

    $forPatryk = new ConversationPresenter($thread->fresh(), $w['patryk']);
    $visible = $forPatryk->processedMessages()['visible'];
    expect($forPatryk->headerTitle())->toBe('Greg')
        ->and($visible->map(fn ($m) => [$m->text, $m->direction])->all())->toBe([['Running late', 'outbound'], ['No problem', 'inbound']]);

    $forGreg = new ConversationPresenter($thread->fresh(), $w['greg']);
    expect($forGreg->headerTitle())->toBe('Patryk')
        ->and($forGreg->processedMessages()['visible']->map(fn ($m) => $m->direction)->all())->toBe(['inbound', 'outbound']);

    // Nothing was saved as inbound: the row is still Hive's own message.
    expect(SmsMessage::query()->where('thread_id', $thread->id)->pluck('direction')->unique()->all())->toBe(['outbound']);
});

it('is unread for the member who has not read the other\'s latest message, and only for members', function () {
    $w = teamWorld();
    Bus::fake();
    $thread = SmsGroupThread::teamThreadFor([$w['patryk']->id, $w['greg']->id], $w['gs']->id);
    app(\App\Services\GroupSmsService::class)->sendToThread($thread, 'Running late', [], $w['patryk']->id);

    expect(SmsGroupThread::unreadCountForUser($w['greg']->id, $w['gs']->id))->toBe(1)
        ->and(SmsGroupThread::unreadCountForUser($w['patryk']->id, $w['gs']->id))->toBe(0)
        ->and(SmsGroupThread::unreadCountForUser($w['cezary']->id, $w['gs']->id))->toBe(0, 'not his thread');

    SmsThreadRead::query()->create(['thread_id' => $thread->id, 'user_id' => $w['greg']->id, 'last_read_message_id' => SmsMessage::max('id')]);
    expect(SmsGroupThread::unreadCountForUser($w['greg']->id, $w['gs']->id))->toBe(0);

    // Visibility: members see it on the Team tab (never among the texts) and may open it; another teammate cannot.
    $this->actingAs($w['greg']);
    $gregRows = collect(Livewire::test(TeamList::class)->instance()->rows);
    expect($gregRows->firstWhere('user_id', $w['patryk']->id))->toMatchArray(['thread_id' => $thread->id, 'preview' => 'Running late', 'unread' => false, 'title' => 'Patryk'])
        ->and(Livewire::test(SmsThreadList::class)->instance()->threads->pluck('id')->all())->not->toContain($thread->id);
    $this->actingAs($w['cezary']);
    expect(collect(Livewire::test(TeamList::class)->instance()->rows)->pluck('thread_id')->filter()->all())->toBe([])
        ->and(Livewire::test(SmsThreadList::class)->instance()->threads->pluck('id')->all())->not->toContain($thread->id)
        ->and(SmsGroupThread::query()->accessibleTo($w['cezary'])->whereKey($thread->id)->exists())->toBeFalse()
        ->and(SmsGroupThread::query()->accessibleTo($w['greg'])->whereKey($thread->id)->exists())->toBeTrue();
});

it('opens the whole team in one thread and keeps a scheduled team message off Telnyx too', function () {
    $w = teamWorld();
    Queue::fake();
    Http::fake();
    $this->actingAs($w['patryk']);

    Livewire::test(TeamList::class)->call('openGroup')->assertDispatchedTo(SmsIndex::class, 'threadSelected');
    $group = SmsGroupThread::query()->where('kind', SmsGroupThread::KIND_TEAM)->sole();
    expect($group->name)->toBe('GS Construction Crew')
        ->and($group->memberIds())->toBe(collect([$w['patryk']->id, $w['greg']->id, $w['cezary']->id])->sort()->values()->all())
        ->and($group->teamTitleFor($w['patryk']))->toBe('GS Construction Crew');

    // A team message scheduled for later reaches SendGroupMms when due; it is marked sent, nothing leaves Hive.
    $scheduled = app(\App\Services\GroupSmsService::class)->sendToThread($group, 'Site meeting moved to 8', [], $w['patryk']->id, now()->subMinute());
    expect($scheduled->status)->toBe('scheduled');
    $scheduled->update(['status' => 'sending']);
    (new SendGroupMms($scheduled->id))->handle();

    expect($scheduled->fresh()->status)->toBe('sent')->and($scheduled->fresh()->provider)->toBe('hive');
    Http::assertNothingSent();
    Queue::assertPushed(SendOutboundSmsBrowserNotifications::class, fn ($job) => $job->messageId === $scheduled->id);
});

it('shows the app each member their own side of a team thread, and the app sends into it without a signature or Telnyx', function () {
    $w = teamWorld();
    Bus::fake([SendGroupMms::class, SendOutboundSmsBrowserNotifications::class]);
    Http::fake();
    $thread = SmsGroupThread::teamThreadFor([$w['patryk']->id, $w['greg']->id], $w['gs']->id);
    app(\App\Services\GroupSmsService::class)->sendToThread($thread, 'Running late', [], $w['patryk']->id);
    app(\App\Services\GroupSmsService::class)->sendToThread($thread, 'No problem', [], $w['greg']->id);

    \Laravel\Sanctum\Sanctum::actingAs($w['greg'], ['app']);
    $data = $this->getJson("/api/app/v1/threads/{$thread->id}/messages")->assertOk()->json('data');
    expect(collect($data)->map(fn ($m) => [$m['body'], $m['direction'], $m['sender_name']])->all())
        ->toBe([['Running late', 'inbound', 'Patryk'], ['No problem', 'outbound', 'Greg']]);
    expect(collect($this->getJson('/api/app/v1/threads')->assertOk()->json('data'))->firstWhere('id', $thread->id)['title'])->toBe('Patryk');

    $this->postJson("/api/app/v1/threads/{$thread->id}/messages", ['body' => 'On my way'])->assertSuccessful();
    $sent = SmsMessage::query()->where('thread_id', $thread->id)->latest('id')->first();
    expect($sent->text)->toBe('On my way')
        ->and($sent->provider)->toBe('hive')
        ->and($sent->sent_by_user_id)->toBe($w['greg']->id);
    Bus::assertNotDispatched(SendGroupMms::class);
    Http::assertNothingSent();

    // Not a member: the app cannot read it (403, the app's rule for every thread it may not see).
    \Laravel\Sanctum\Sanctum::actingAs($w['cezary'], ['app']);
    $this->getJson("/api/app/v1/threads/{$thread->id}/messages")->assertForbidden();
});

it('takes the team from /phone-system: an unticked colleague leaves the roster, the group thread and the Team tab, and cannot be called', function () {
    $w = teamWorld();
    Bus::fake();
    Http::fake();
    // Andzelina is employed (an admin) but not part of the field team.
    $andzelina = User::query()->create(['first_name' => 'Andzelina', 'last_name' => 'Szady', 'email' => 'a.'.uniqid().'@gs.construction', 'cell_phone' => '8479246893', 'password' => bcrypt('x'), 'primary_vendor_id' => $w['gs']->id, 'registration' => ['registered' => true]]);
    $w['gs']->users()->attach($andzelina->id, ['role_id' => 1, 'is_employed' => 1]);
    $this->actingAs($w['patryk']);

    // Nothing picked yet: everyone employed is on the team, and the group thread was made with all four.
    expect(\App\Support\Team\TeamRoster::memberIds($w['gs']->fresh()))->toBe(collect([$w['patryk']->id, $w['greg']->id, $w['cezary']->id, $andzelina->id])->sort()->values()->all());
    Livewire::test(TeamList::class)->call('openGroup');
    $group = SmsGroupThread::query()->where('kind', SmsGroupThread::KIND_TEAM)->whereNotNull('name')->sole();
    expect($group->memberIds())->toContain($andzelina->id);
    app(\App\Services\GroupSmsService::class)->sendToThread($group, 'Site meeting moved to 8', [], $w['patryk']->id);

    // /phone-system: tick Patryk, Greg and Czarek only.
    Livewire::test(\App\Livewire\Vendors\PhoneSystemOptions::class)
        ->assertSeeHtml('data-team-roster')
        ->assertSet('team_member_user_ids', array_map('strval', collect([$w['patryk']->id, $w['greg']->id, $w['cezary']->id, $andzelina->id])->sort()->values()->all()))
        ->set('team_member_user_ids', [(string) $w['patryk']->id, (string) $w['greg']->id, (string) $w['cezary']->id])
        ->call('save')
        ->assertHasNoErrors();
    $gs = $w['gs']->fresh();
    expect(data_get($gs->options, \App\Support\Team\TeamRoster::OPTION))->toEqualCanonicalizing([$w['patryk']->id, $w['greg']->id, $w['cezary']->id])
        ->and(\App\Support\Team\TeamRoster::memberIds($gs))->toBe(collect([$w['patryk']->id, $w['greg']->id, $w['cezary']->id])->sort()->values()->all());

    // The same group thread (and its history) carries on without her — and a thread
    // still named "… Team" from before takes the crew name.
    $group->update(['name' => 'GS Construction Team']);
    \App\Support\Team\TeamRoster::syncGroupThread($gs);
    $group->refresh();
    expect($group->name)->toBe('GS Construction Crew')
        ->and($group->memberIds())->not->toContain($andzelina->id)
        ->and($group->memberIds())->toHaveCount(3)
        ->and($group->messages()->count())->toBe(1)
        ->and(SmsGroupThread::query()->where('kind', SmsGroupThread::KIND_TEAM)->whereNotNull('name')->count())->toBe(1);
    Livewire::test(TeamList::class)->call('openGroup')->assertDispatchedTo(SmsIndex::class, 'threadSelected', threadId: $group->id);

    // The crew thread lists every other member under its title, each a Hive call; a pair thread does not.
    Livewire::test(SmsConversation::class, ['threadId' => $group->id])
        ->assertSeeHtml('data-team-members')
        ->assertSeeHtml('data-team-call-crew')
        ->assertSeeHtml('data-team-call="'.$w['greg']->id.'"')
        ->assertSeeHtml('data-team-call="'.$w['cezary']->id.'"')
        ->assertDontSeeHtml('data-team-call="'.$w['patryk']->id.'"')
        ->assertDontSeeHtml('data-team-call="'.$andzelina->id.'"');
    $pair = SmsGroupThread::teamThreadFor([$w['patryk']->id, $w['greg']->id], $gs->id);
    Livewire::test(SmsConversation::class, ['threadId' => $pair->id])->assertDontSeeHtml('data-team-members')->assertDontSeeHtml('data-team-call-crew')->assertSeeHtml('data-team-call="'.$w['greg']->id.'"');
    Livewire::test(TeamList::class)->assertSeeHtml('data-team-call-crew');

    // The roster, the Team tab and the phone follow the pick.
    expect(collect(Livewire::test(TeamList::class)->instance()->rows)->pluck('title')->all())->toBe(['GS Construction Crew', 'Cezary', 'Greg']);
    Livewire::test(SmsIndex::class)->assertSet('showTeam', true);
    $this->actingAs($andzelina);
    Livewire::test(SmsIndex::class)->assertSet('showTeam', false);
    expect(\App\Livewire\TeamPhone::enabledFor($andzelina))->toBeFalse()
        ->and(\App\Livewire\TeamPhone::enabledFor($w['patryk']))->toBeTrue();
    expect(app(\App\Services\Voice\InternalCalls::class)->start($w['patryk'], $andzelina)['ok'])->toBeFalse();

    // Someone who left the company drops off even when still ticked.
    $gs->users()->updateExistingPivot($w['cezary']->id, ['is_employed' => 0]);
    expect(\App\Support\Team\TeamRoster::memberIds($gs->fresh()))->toBe(collect([$w['patryk']->id, $w['greg']->id])->sort()->values()->all());
});

it('lets a team member who is not an admin (Czarek) into /messages for the Team tab alone', function () {
    $w = teamWorld();
    Bus::fake();
    $w['gs']->users()->updateExistingPivot($w['cezary']->id, ['role_id' => 2]);
    $cezary = $w['cezary']->fresh();
    expect($cezary->vendor_role)->toBe('Member');
    $pair = SmsGroupThread::teamThreadFor([$w['patryk']->id, $cezary->id], $w['gs']->id);
    app(\App\Services\GroupSmsService::class)->sendToThread($pair, 'Bring the laser tomorrow', [], $w['patryk']->id);
    $text = SmsGroupThread::query()->create(['from_number' => '+12247354200', 'participants' => ['+18475551212'], 'vendor_id' => $w['gs']->id, 'last_activity_at' => now()]);
    $this->actingAs($cezary);

    // The notification's link opens his team thread; the page is the Team tab only.
    Livewire::withQueryParams(['threadId' => $pair->id, 'messageId' => 1])->test(SmsIndex::class)
        ->assertSet('teamOnly', true)->assertSet('activeTab', 'team')->assertSet('threadId', $pair->id)
        ->assertSeeLivewire(TeamList::class)
        ->assertDontSeeHtml("switchTab('messages')")
        ->assertDontSeeHtml("switchTab('calls')")
        ->assertDontSeeLivewire(SmsThreadList::class);

    // A client's text is out of reach by link, by tap and in the conversation pane.
    Livewire::withQueryParams(['threadId' => $text->id])->test(SmsIndex::class)->assertSet('threadId', null)->assertSet('activeTab', 'team');
    Livewire::test(SmsIndex::class)->call('selectThread', $text->id)->assertSet('threadId', null)
        ->call('switchToThread', $text->id)->assertSet('threadId', null)->assertSet('activeTab', 'team')
        ->call('selectThread', $pair->id)->assertSet('threadId', $pair->id);
    Livewire::test(SmsConversation::class, ['threadId' => $text->id])->assertSet('threadId', null);
    Livewire::test(SmsConversation::class, ['threadId' => $pair->id])->assertSet('threadId', $pair->id)->assertSee('Bring the laser tomorrow')
        ->set('newMessage', 'Will do')->call('sendMessage')->assertHasNoErrors();
    expect(SmsMessage::query()->where('thread_id', $pair->id)->where('text', 'Will do')->where('sent_by_user_id', $cezary->id)->exists())->toBeTrue();

    // Off the roster he is back to no access at all; an admin is never team-only.
    $w['gs']->forceFill(['options' => (object) ['short_name' => 'GS Construction', \App\Support\Team\TeamRoster::OPTION => [$w['patryk']->id, $w['greg']->id]]])->save();
    Livewire::test(SmsIndex::class)->assertForbidden();
    $this->actingAs($w['patryk']);
    Livewire::test(SmsIndex::class)->assertSet('teamOnly', false)->assertSeeHtml("switchTab('messages')");
});
