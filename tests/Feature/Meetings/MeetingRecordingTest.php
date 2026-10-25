<?php

use App\Jobs\CheckMeetingTranscript;
use App\Jobs\ExtractProjectActionItems;
use App\Jobs\PrepareMeetingRecording;
use App\Livewire\Meetings\MeetingRecorder;
use App\Livewire\Projects\ProjectCommunications;
use App\Models\MeetingRecording;
use App\Models\ProjectActionItem;
use App\Models\User;
use App\Services\ActionItems\ProjectActionItemExtractor;
use App\Services\Communications\ProjectCommunicationsTimeline;
use App\Services\Meetings\MeetingTranscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/t3-fixtures.php';
require_once __DIR__.'/../../Support/service-call-fixtures.php';

/*
 * In-person meetings recorded from Hive (2026-10-24): Record on a project or
 * a saved Meet, the audio posted in pieces while it records, then
 * transcribed (AssemblyAI) and summarized into the project's planner and
 * communications timeline, like a call.
 */

beforeEach(function () {
    config([
        'meeting_recording.disk' => 'local',
        'services.assemblyai.api_key' => 'aai-test',
        'services.openai.api_key' => 'test-key',
        'call_recording.summarization.driver' => 'openai',
    ]);
    Storage::fake('local');
});

/** Patryk as an admin of a company with a planner-active project. */
function meetingWorld(): array
{
    $world = serviceCallWorld('active');
    $patryk = User::find(1);
    $world['vendor']->users()->syncWithoutDetaching([1 => ['role_id' => 1, 'is_employed' => 1]]);
    $patryk->forceFill(['primary_vendor_id' => $world['vendor']->id, 'registration' => ['registered' => true]])->save();
    test()->actingAs($patryk->fresh());

    return $world + ['patryk' => $patryk->fresh()];
}

it('starts a recording only once everyone agreed to it, and only for Patryk and Greg for now', function () {
    ['project' => $project, 'patryk' => $patryk] = meetingWorld();

    $page = Livewire::actingAs($patryk)->test(MeetingRecorder::class, ['projectId' => $project->id]);

    expect($page->instance()->start(false))->toBe(['error' => 'Confirm everyone agreed to be recorded first.'])
        ->and(MeetingRecording::count())->toBe(0);

    $started = $page->instance()->start(true, 'audio/webm;codecs=opus', 'DJI Mic Mini');
    $recording = MeetingRecording::sole();

    expect($started['id'])->toBe($recording->id)
        ->and($started['upload_url'])->toBe(route('meeting-recordings.chunks', $recording))
        ->and($recording->status)->toBe(MeetingRecording::STATUS_RECORDING)
        ->and($recording->consent_confirmed_at)->not->toBeNull()
        ->and($recording->microphone)->toBe('DJI Mic Mini')
        ->and($recording->project_id)->toBe($project->id);

    $someone = sec3_makeAdmin($project->createdByVendor ?? \App\Models\Vendor::find($project->belongs_to_vendor_id));
    Livewire::actingAs($someone)->test(MeetingRecorder::class, ['projectId' => $project->id])->assertForbidden();
});

it('takes the audio in pieces from the person recording, while it records', function () {
    ['project' => $project, 'patryk' => $patryk] = meetingWorld();
    $recording = MeetingRecording::factory()->create(['project_id' => $project->id, 'recorded_by_user_id' => $patryk->id, 'audio_disk' => 'local']);
    $url = route('meeting-recordings.chunks', $recording);

    $this->actingAs($patryk)->post($url, ['seq' => 0, 'chunk' => UploadedFile::fake()->create('piece-0', 40, 'audio/webm')])->assertOk();
    $this->actingAs($patryk)->post($url, ['seq' => 1, 'chunk' => UploadedFile::fake()->create('piece-1', 40, 'audio/webm')])->assertOk();

    Storage::disk('local')->assertExists($recording->chunkDirectory().'/000000.part');
    Storage::disk('local')->assertExists($recording->chunkDirectory().'/000001.part');
    expect($recording->fresh()->chunk_count)->toBe(2);

    $this->actingAs(User::factory()->create())->post($url, ['seq' => 2, 'chunk' => UploadedFile::fake()->create('x', 4)])->assertForbidden();

    $recording->update(['status' => MeetingRecording::STATUS_PROCESSING]);
    $this->actingAs($patryk)->post($url, ['seq' => 2, 'chunk' => UploadedFile::fake()->create('late', 4)])->assertStatus(409);
});

it('hands a stopped recording over to be transcribed', function () {
    Queue::fake();
    ['project' => $project, 'patryk' => $patryk] = meetingWorld();
    $page = Livewire::actingAs($patryk)->test(MeetingRecorder::class, ['projectId' => $project->id]);
    $started = $page->instance()->start(true, 'audio/webm');

    $page->call('finish', $started['id'], 180, 1795);

    $recording = MeetingRecording::find($started['id']);
    expect($recording->status)->toBe(MeetingRecording::STATUS_PROCESSING)
        ->and($recording->duration_seconds)->toBe(1795)
        ->and($recording->chunk_count)->toBe(180)
        ->and($recording->ended_at)->not->toBeNull();
    Queue::assertPushed(PrepareMeetingRecording::class, fn ($job) => $job->meetingRecordingId === $recording->id);

    // Someone else's, or a stopped one, can't be finished or thrown away.
    $page->call('discard', $recording->id);
    expect(MeetingRecording::find($recording->id))->not->toBeNull();
});

it('takes a recording made in Voice Memos as an uploaded file, in pieces, and transcribes it like a live one', function () {
    Queue::fake();
    ['project' => $project, 'patryk' => $patryk] = meetingWorld();
    config(['meeting_recording.max_upload_mb' => 100, 'meeting_recording.upload_piece_kb' => 5120]);
    $page = Livewire::actingAs($patryk)->test(MeetingRecorder::class, ['projectId' => $project->id]);
    $savedAt = now()->subHours(3)->startOfSecond();

    expect($page->instance()->startUpload(false, 'Kitchen walkthrough.m4a', 'audio/x-m4a', 20_000_000, $savedAt->getTimestampMs()))->toBe(['error' => 'Confirm everyone agreed to be recorded first.'])
        ->and($page->instance()->startUpload(true, 'huge.wav', 'audio/wav', 200 * 1024 * 1024, null)['error'])->toContain('up to 100 MB')
        ->and(MeetingRecording::count())->toBe(0);

    $started = $page->instance()->startUpload(true, 'Kitchen walkthrough.m4a', 'audio/x-m4a', 20_000_000, $savedAt->getTimestampMs());
    $recording = MeetingRecording::sole();

    expect($started)->toBe(['id' => $recording->id, 'upload_url' => route('meeting-recordings.chunks', $recording), 'piece_bytes' => 5120 * 1024])
        ->and($recording->source)->toBe(MeetingRecording::SOURCE_UPLOAD)
        ->and($recording->status)->toBe(MeetingRecording::STATUS_RECORDING)
        ->and($recording->consent_confirmed_at)->not->toBeNull()
        ->and($recording->microphone)->toBe('Uploaded file · Kitchen walkthrough.m4a')
        ->and($recording->audioExtension())->toBe('m4a')
        // The file's date is when it was saved: the end of the meeting.
        ->and($recording->ended_at->equalTo($savedAt))->toBeTrue();

    $url = $started['upload_url'];
    $this->actingAs($patryk)->post($url, ['seq' => 0, 'chunk' => UploadedFile::fake()->create('piece-0', 5120, 'audio/x-m4a')])->assertOk();
    $this->actingAs($patryk)->post($url, ['seq' => 1, 'chunk' => UploadedFile::fake()->create('piece-1', 300, 'audio/x-m4a')])->assertOk();

    $page->call('finish', $recording->id, 2, 2700);

    $recording->refresh();
    expect($recording->status)->toBe(MeetingRecording::STATUS_PROCESSING)
        ->and($recording->chunk_count)->toBe(2)
        ->and($recording->duration_seconds)->toBe(2700)
        ->and($recording->ended_at->equalTo($savedAt))->toBeTrue()
        ->and($recording->started_at->equalTo($savedAt->copy()->subSeconds(2700)))->toBeTrue();
    Queue::assertPushed(PrepareMeetingRecording::class, fn ($job) => $job->meetingRecordingId === $recording->id);

    // The card offers the upload and lists the file as one.
    $page->assertSee('Upload and transcribe')->assertSee('uploaded file')->assertSee('keeps recording with the screen locked');
});

it('places an uploaded recording in time from the file\'s date once ffmpeg has measured it', function () {
    Queue::fake();
    ['project' => $project, 'patryk' => $patryk] = meetingWorld();
    $savedAt = now()->subDay()->startOfSecond();
    $recording = MeetingRecording::factory()->create([
        'project_id' => $project->id, 'recorded_by_user_id' => $patryk->id, 'status' => MeetingRecording::STATUS_PROCESSING,
        'source' => MeetingRecording::SOURCE_UPLOAD, 'mime_type' => 'audio/x-m4a', 'audio_disk' => 'local',
        'started_at' => $savedAt, 'ended_at' => $savedAt, 'duration_seconds' => 0,
    ]);
    Storage::disk('local')->put($recording->chunkDirectory().'/000000.part', 'm4a-bytes');
    Process::fake(['*ffmpeg*' => Process::result(), '*ffprobe*' => Process::result('1500.2')]);
    $transcriber = Mockery::mock(MeetingTranscriber::class);
    $transcriber->shouldReceive('submit')->once()->andReturn('aai-up');

    (new PrepareMeetingRecording($recording->id))->handle($transcriber);

    $recording->refresh();
    expect($recording->duration_seconds)->toBe(1500)
        ->and($recording->started_at->equalTo($savedAt->copy()->subSeconds(1500)))->toBeTrue()
        ->and($recording->ended_at->equalTo($savedAt))->toBeTrue()
        ->and($recording->assemblyai_id)->toBe('aai-up');
    Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), '/joined.m4a'));

    // On the project's timeline it sits at the time of the meeting, not of the upload.
    $recording->update(['status' => MeetingRecording::STATUS_READY, 'summary' => 'Walked the kitchen.']);
    $items = collect(app(ProjectCommunicationsTimeline::class)->forProject($project, null, 50, ProjectCommunicationsTimeline::ALL_KINDS)['items'])->where('kind', 'meeting');
    expect($items->first()['at'])->toBe($savedAt->copy()->subSeconds(1500)->toIso8601String());
});

it('joins the pieces, compresses them and hands them to AssemblyAI without waiting on it', function () {
    Queue::fake();
    ['project' => $project, 'patryk' => $patryk] = meetingWorld();
    $recording = MeetingRecording::factory()->create(['project_id' => $project->id, 'recorded_by_user_id' => $patryk->id, 'status' => MeetingRecording::STATUS_PROCESSING, 'audio_disk' => 'local', 'duration_seconds' => 610]);
    Storage::disk('local')->put($recording->chunkDirectory().'/000000.part', 'webm-head');
    Storage::disk('local')->put($recording->chunkDirectory().'/000001.part', 'webm-rest');

    Process::fake([
        '*ffmpeg*' => Process::result(),
        '*ffprobe*' => Process::result('612.4'),
    ]);
    $transcriber = Mockery::mock(MeetingTranscriber::class);
    $transcriber->shouldReceive('submit')->once()->andReturn('aai-123');

    (new PrepareMeetingRecording($recording->id))->handle($transcriber);

    $recording->refresh();
    expect($recording->assemblyai_id)->toBe('aai-123')
        ->and($recording->duration_seconds)->toBe(612)
        ->and($recording->audio_path)->toBe("meeting-recordings/{$recording->id}/meeting.mp3")
        // Only the MP3 is kept: the joined original goes.
        ->and(file_exists(Storage::disk('local')->path("meeting-recordings/{$recording->id}/joined.webm")))->toBeFalse();
    Storage::disk('local')->assertMissing($recording->chunkDirectory().'/000000.part');
    Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), '-ac 1 -ar 16000'));
    Queue::assertPushed(CheckMeetingTranscript::class, fn ($job) => $job->meetingRecordingId === $recording->id && $job->check === 1);
});

it('calls a recording of a few seconds empty instead of transcribing it', function () {
    Queue::fake();
    ['project' => $project, 'patryk' => $patryk] = meetingWorld();
    $recording = MeetingRecording::factory()->create(['project_id' => $project->id, 'recorded_by_user_id' => $patryk->id, 'status' => MeetingRecording::STATUS_PROCESSING, 'audio_disk' => 'local']);
    Storage::disk('local')->put($recording->chunkDirectory().'/000000.part', 'tiny');
    Process::fake(['*ffmpeg*' => Process::result(), '*ffprobe*' => Process::result('2.1')]);
    $transcriber = Mockery::mock(MeetingTranscriber::class);
    $transcriber->shouldNotReceive('submit');

    (new PrepareMeetingRecording($recording->id))->handle($transcriber);

    expect($recording->fresh()->status)->toBe(MeetingRecording::STATUS_EMPTY);
    Queue::assertNotPushed(CheckMeetingTranscript::class);
});

it('asks again while AssemblyAI works, then summarizes the meeting and sends its action items to the planner', function () {
    Queue::fake();
    ['project' => $project, 'patryk' => $patryk] = meetingWorld();
    $recording = MeetingRecording::factory()->create(['project_id' => $project->id, 'recorded_by_user_id' => $patryk->id, 'status' => MeetingRecording::STATUS_PROCESSING, 'assemblyai_id' => 'aai-123']);

    Http::fake([
        'api.assemblyai.com/*' => Http::sequence()
            ->push(['status' => 'processing'])
            ->push(['status' => 'completed', 'language_code' => 'en', 'utterances' => [
                ['speaker' => 'A', 'start' => 0, 'end' => 4000, 'text' => "Erin, I'll send you the revised quote by Friday."],
                ['speaker' => 'B', 'start' => 4000, 'end' => 7000, 'text' => 'Great, and order the 36-inch vanity.'],
            ]]),
        'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'summary' => 'Walked the bathroom with Erin; agreed on the vanity and a revised quote.',
            'action_items' => ['Patryk: Send the revised quote to Erin by Friday', 'Patryk: Order the 36-inch vanity'],
            'topics' => ['vanity', 'quote'],
            'next_steps' => ['Quote Friday'],
            'recording_has_no_message' => false,
        ])]]]]),
    ]);

    (new CheckMeetingTranscript($recording->id))->handle(app(MeetingTranscriber::class), app(\App\Services\Meetings\MeetingSummarizer::class));
    Queue::assertPushed(CheckMeetingTranscript::class, fn ($job) => $job->check === 2);
    expect($recording->fresh()->status)->toBe(MeetingRecording::STATUS_PROCESSING);

    (new CheckMeetingTranscript($recording->id, 2))->handle(app(MeetingTranscriber::class), app(\App\Services\Meetings\MeetingSummarizer::class));

    $recording->refresh();
    expect($recording->status)->toBe(MeetingRecording::STATUS_READY)
        ->and($recording->transcript)->toContain("Speaker A: Erin, I'll send you the revised quote by Friday.")
        ->and($recording->summary)->toContain('vanity')
        ->and($recording->action_items)->toHaveCount(2)
        ->and($recording->purge_after)->not->toBeNull();
    Queue::assertPushed(ExtractProjectActionItems::class, fn ($job) => $job->projectId === $project->id);

    // The summary was asked for as a meeting worn on Patryk's mic.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'openai.com') && str_contains(json_encode($request->data()), 'in-person meetings'));
});

it('puts a meeting\'s action items on the planner, citing the meeting', function () {
    ['project' => $project, 'patryk' => $patryk] = meetingWorld();
    MeetingRecording::factory()->ready(['Patryk: Order the 36-inch vanity'])->create(['project_id' => $project->id, 'recorded_by_user_id' => $patryk->id]);

    (new ExtractProjectActionItems($project->id))->handle(app(ProjectActionItemExtractor::class));

    $item = ProjectActionItem::where('project_id', $project->id)->where('title', 'Order the 36-inch vanity')->sole();
    expect($item->sources[0]['kind'])->toBe('meeting')
        ->and(ProjectActionItem::sourcePhrase($item->sources[0]))->toStartWith('From a meeting');
});

it('shows meetings in the project\'s web timeline, but not yet in the app\'s', function () {
    ['project' => $project, 'patryk' => $patryk] = meetingWorld();
    $meeting = MeetingRecording::factory()->ready(['Patryk: Order the vanity'])->create(['project_id' => $project->id, 'recorded_by_user_id' => $patryk->id]);
    MeetingRecording::factory()->create(['project_id' => $project->id, 'recorded_by_user_id' => $patryk->id]); // still recording: not shown

    $timeline = app(ProjectCommunicationsTimeline::class);
    $web = collect($timeline->forProject($project, null, 50, ProjectCommunicationsTimeline::ALL_KINDS)['items'])->where('kind', 'meeting');
    $app = collect($timeline->forProject($project)['items'])->where('kind', 'meeting');

    expect($web->pluck('id')->all())->toBe([$meeting->id])
        ->and($web->first()['title'])->toBe('In-person meeting')
        ->and($app)->toBeEmpty();

    Livewire::actingAs($patryk)
        ->test(ProjectCommunications::class, ['project' => $project])
        ->set('kind', 'meeting')
        ->assertSee('In-person meeting')
        ->call('selectMeeting', $meeting->id)
        ->assertSet('showMeetingModal', true)
        ->assertSee('Walked the kitchen with the homeowner.')
        ->assertSee('Patryk: Order the vanity');
});
