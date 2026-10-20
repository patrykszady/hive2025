<?php

/**
 * Email with project stakeholders is kept — who it is with, the project,
 * the real attachments — and nothing else is: not internal mail, not
 * strangers, not logos and calendar invites.
 */

use App\Jobs\SummarizeEmailMessage;
use App\Models\CompanyEmail;
use App\Models\EmailMessage;
use App\Models\ProjectStatus;
use App\Models\Vendor;
use App\Services\Emails\StakeholderEmailStore;
use App\Services\Emails\StakeholderEmailSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/t3-fixtures.php';
require_once __DIR__.'/../../Support/service-call-fixtures.php';

const NYLAS_TEST = 'https://api.us.nylas.com';

beforeEach(function () {
    config([
        'emails.stakeholder_sync.enabled' => true,
        'nylas.api_key' => 'nylas-test',
        'nylas.api_uri' => NYLAS_TEST,
        'scout.driver' => null,
    ]);
    Storage::fake('files');
    Queue::fake();
});

function nylasMessage(array $world, array $overrides = []): array
{
    return array_replace([
        'id' => 'msg-'.uniqid(),
        'thread_id' => 'thread-1',
        'date' => now()->subHour()->getTimestamp(),
        'subject' => 'Grout',
        'snippet' => 'I have officially noticed grout cracking',
        'from' => [['name' => 'Katie Love', 'email' => $world['katie']->email]],
        'to' => [['email' => $world['mailbox']]],
        'cc' => [['email' => $world['jon']->email]],
        'body' => '<p>I have officially noticed grout cracking in both bathrooms.</p>',
        'headers' => [['name' => 'Message-ID', 'value' => '<katie-'.uniqid().'@mail.gmail.com>']],
        'attachments' => [],
    ], $overrides);
}

it('keeps a client\'s email with the project and only its real attachments', function () {
    $world = serviceCallWorld();
    Http::fake([
        NYLAS_TEST.'/v3/grants/grant-1/attachments/att-photo/download*' => Http::response(str_repeat('j', 40000)),
        NYLAS_TEST.'/v3/grants/grant-1/attachments/att-quote/download*' => Http::response('%PDF quote'),
    ]);

    $row = app(StakeholderEmailStore::class)->store(nylasMessage($world, ['attachments' => [
        ['id' => 'att-photo', 'filename' => 'IMG_2231.jpg', 'content_type' => 'image/jpeg', 'size' => 40000, 'is_inline' => false],
        ['id' => 'att-quote', 'filename' => 'grout-quote.pdf', 'content_type' => 'application/pdf', 'size' => 10, 'is_inline' => false],
        ['id' => 'att-logo', 'filename' => 'image001.png', 'content_type' => 'image/png', 'size' => 4000, 'is_inline' => false],
        ['id' => 'att-inline', 'filename' => 'photo.jpg', 'content_type' => 'image/jpeg', 'size' => 90000, 'is_inline' => true],
        ['id' => 'att-ics', 'filename' => 'invite.ics', 'content_type' => 'text/calendar', 'size' => 900, 'is_inline' => false],
    ]]), 'grant-1', $world['mailbox']);

    expect($row->direction)->toBe(EmailMessage::DIRECTION_INBOUND)
        ->and($row->client_id)->toBe($world['client']->id)
        ->and($row->user_id)->toBe($world['katie']->id)
        ->and($row->project_id)->toBe($world['project']->id)
        ->and($row->body_text)->toContain('grout cracking in both bathrooms')
        ->and(collect($row->attachments)->pluck('name')->all())->toBe(['IMG_2231.jpg', 'grout-quote.pdf']);

    Storage::disk('files')->assertExists($row->attachments[1]['path']);
    expect(Storage::disk('files')->get($row->attachments[1]['path']))->toBe('%PDF quote');
    Queue::assertPushed(SummarizeEmailMessage::class, fn ($job) => $job->emailMessageId === $row->id);
    Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'att-logo') || str_contains($request->url(), 'att-ics'));
});

it('keeps what the team sends a client as outbound', function () {
    $world = serviceCallWorld();

    $row = app(StakeholderEmailStore::class)->store(nylasMessage($world, [
        'from' => [['name' => 'Patryk', 'email' => $world['mailbox']]],
        'to' => [['email' => $world['katie']->email]],
        'cc' => [],
    ]), 'grant-1', $world['mailbox']);

    expect($row->direction)->toBe(EmailMessage::DIRECTION_OUTBOUND)
        ->and($row->user_id)->toBe($world['katie']->id)
        ->and($row->project_id)->toBe($world['project']->id);
});

it('files a vendor\'s email under the projects it is on', function () {
    $world = serviceCallWorld();
    $sub = Vendor::factory()->create(['business_name' => 'Tile Pros '.uniqid(), 'business_email' => 'office.'.uniqid().'@tilepros.test']);
    $world['project']->vendors()->attach($sub->id, ['client_id' => $world['client']->id]);

    $row = app(StakeholderEmailStore::class)->store(nylasMessage($world, [
        'from' => [['name' => 'Tile Pros', 'email' => $sub->business_email]],
        'cc' => [],
    ]), 'grant-1', $world['mailbox']);

    expect($row->vendor_id)->toBe($sub->id)
        ->and($row->client_id)->toBeNull()
        ->and($row->project_id)->toBe($world['project']->id);
});

it('leaves internal mail, strangers and switched-off sync alone', function () {
    $world = serviceCallWorld();
    $store = app(StakeholderEmailStore::class);

    expect($store->store(nylasMessage($world, ['from' => [['email' => 'greg@gs.construction']], 'cc' => []]), 'grant-1', $world['mailbox']))->toBeNull()
        ->and($store->store(nylasMessage($world, ['from' => [['email' => 'promo@deals.test']], 'cc' => []]), 'grant-1', $world['mailbox']))->toBeNull();

    config(['emails.stakeholder_sync.enabled' => false]);
    expect($store->store(nylasMessage($world), 'grant-1', $world['mailbox']))->toBeNull()
        ->and(EmailMessage::count())->toBe(0);
});

it('keeps one row for an email that reached two inboxes', function () {
    $world = serviceCallWorld();
    $message = nylasMessage($world);
    $store = app(StakeholderEmailStore::class);

    $first = $store->store($message, 'grant-1', $world['mailbox']);
    $copy = $store->store(['id' => 'msg-crew-copy'] + $message, 'grant-1', $world['mailbox']);

    expect($copy->id)->toBe($first->id)->and(EmailMessage::count())->toBe(1);
});

it('leaves the project open for the AI read when the client has several', function () {
    $world = serviceCallWorld();
    $kitchen = sec3_makeProject($world['vendor'], $world['client'], ['project_name' => 'Kitchen']);
    ProjectStatus::create(['project_id' => $kitchen->id, 'belongs_to_vendor_id' => $world['vendor']->id, 'status_code' => 6, 'start_date' => today()->format('Y-m-d')]);

    $row = app(StakeholderEmailStore::class)->store(nylasMessage($world), 'grant-1', $world['mailbox']);

    expect($row->project_id)->toBeNull()
        ->and($row->candidate_project_ids)->toEqualCanonicalizing([$kitchen->id, $world['project']->id]);
});

it('tells real files from signature art', function (array $attachment, bool $keep) {
    expect(StakeholderEmailStore::isWorthKeeping($attachment))->toBe($keep);
})->with([
    'pdf' => [['filename' => 'contract.pdf', 'content_type' => 'application/pdf', 'size' => 50000], true],
    'photo' => [['filename' => 'IMG_1.HEIC', 'content_type' => 'image/heic', 'size' => 900000], true],
    'spreadsheet' => [['filename' => 'takeoff.xlsx', 'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'size' => 20000], true],
    'outlook logo' => [['filename' => 'image001.png', 'content_type' => 'image/png', 'size' => 60000], false],
    'tiny icon' => [['filename' => 'badge.png', 'content_type' => 'image/png', 'size' => 3000], false],
    'inline' => [['filename' => 'photo.jpg', 'content_type' => 'image/jpeg', 'size' => 90000, 'is_inline' => true], false],
    'calendar invite' => [['filename' => 'invite.ics', 'content_type' => 'text/calendar', 'size' => 900], false],
    'smime' => [['filename' => 'smime.p7s', 'content_type' => 'application/pkcs7-signature', 'size' => 4000], false],
    'winmail' => [['filename' => 'winmail.dat', 'content_type' => 'application/ms-tnef', 'size' => 9000], false],
]);

it('syncs every connected inbox and sent folder from the watermark', function () {
    $world = serviceCallWorld();
    $inbound = nylasMessage($world);
    $sent = nylasMessage($world, ['from' => [['email' => $world['mailbox']]], 'to' => [['email' => $world['katie']->email]], 'cc' => []]);

    Http::fake([
        NYLAS_TEST.'/v3/grants/grant-1/folders*' => Http::response(['data' => [
            ['id' => 'f-inbox', 'name' => 'Inbox', 'attributes' => ['\\Inbox']],
            ['id' => 'f-sent', 'name' => 'Sent Items', 'attributes' => ['\\Sent']],
            ['id' => 'f-junk', 'name' => 'Junk Email'],
        ]]),
        NYLAS_TEST.'/v3/grants/grant-1/messages*' => function (HttpRequest $request) use ($inbound, $sent) {
            return Http::response(['data' => $request['in'] === 'f-sent' ? [$sent] : [$inbound]]);
        },
    ]);

    $report = app(StakeholderEmailSync::class)->run();

    expect(collect($report)->where('mailbox', $world['mailbox'])->pluck('folder')->all())->toBe(['inbox', 'sent'])
        ->and(EmailMessage::pluck('direction')->sort()->values()->all())->toBe(['inbound', 'outbound'])
        ->and(Cache::get("emails:sync:grant-1:{$world['mailbox']}:inbox"))->toBe($inbound['date'] - 1);
    Http::assertNotSent(fn (HttpRequest $request) => ($request['in'] ?? null) === 'f-junk');
});

it('serves an attachment only through its signed link', function () {
    Storage::disk('files')->put('emails/1/2026/10/quote.pdf', '%PDF quote');
    $email = EmailMessage::factory()->create([
        'attachments' => [['path' => 'emails/1/2026/10/quote.pdf', 'name' => 'grout-quote.pdf', 'mime' => 'application/pdf', 'size' => 10]],
    ]);

    $this->get($email->attachmentUrl(0))->assertSuccessful()->assertHeader('Content-Type', 'application/pdf');
    $this->get("/emails/{$email->id}/attachments/0/signed")->assertForbidden();
    $this->get(str_replace('/attachments/0/', '/attachments/1/', $email->attachmentUrl(0)))->assertForbidden();
});
