<?php

namespace App\Livewire\Meetings;

use App\Jobs\PrepareMeetingRecording;
use App\Models\MeetingRecording;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The Record button for in-person meetings, on a project page and on a
 * saved Meet. The browser records whatever microphone is chosen (a clip-on
 * like the DJI Mic Mini, or the device's own) and posts the audio in pieces
 * while it records (MeetingRecordingChunkController); Stop hands the
 * recording to PrepareMeetingRecording, which transcribes and summarizes it
 * into the project's planner and communications timeline. A recording made
 * elsewhere (Voice Memos on the phone, a handheld recorder) is uploaded as a
 * file through the same pieces and takes the same road.
 *
 * Illinois needs everyone's consent to record a private conversation:
 * start() refuses without it, and the moment is kept on the recording.
 */
class MeetingRecorder extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public ?int $projectId = null;

    #[Locked]
    public ?int $taskId = null;

    public function mount(?int $projectId = null, ?int $taskId = null): void
    {
        $this->authorize('record-meetings');

        if ($taskId) {
            $task = Task::visibleTo($taskId);
            abort_unless($task, 404);
            $this->taskId = $task->id;
            $projectId = $task->project_id;
        }

        $project = Project::query()->find($projectId);
        abort_unless($project, 404);
        $this->authorize('update', $project);

        $this->projectId = $project->id;
    }

    /**
     * Begin a recording. Returns where to post its audio pieces, or an error.
     *
     * @return array{id?: int, upload_url?: string, max_seconds?: int, error?: string}
     */
    public function start(bool $consent, ?string $mimeType = null, ?string $microphone = null): array
    {
        $this->authorize('record-meetings');
        $this->authorize('update', $this->project);

        if (! $consent) {
            return ['error' => 'Confirm everyone agreed to be recorded first.'];
        }

        $recording = MeetingRecording::create([
            'belongs_to_vendor_id' => $this->project->belongs_to_vendor_id,
            'project_id' => $this->projectId,
            'task_id' => $this->taskId,
            'recorded_by_user_id' => auth()->id(),
            'status' => MeetingRecording::STATUS_RECORDING,
            'consent_confirmed_at' => now(),
            'microphone' => $microphone ? mb_substr($microphone, 0, 250) : null,
            'mime_type' => $mimeType ? mb_substr($mimeType, 0, 60) : null,
            'audio_disk' => config('meeting_recording.disk'),
            'started_at' => now(),
        ]);

        return [
            'id' => $recording->id,
            'upload_url' => route('meeting-recordings.chunks', $recording),
            'max_seconds' => (int) config('meeting_recording.max_minutes', 240) * 60,
        ];
    }

    /**
     * Take a recording made elsewhere (Voice Memos on the phone, a handheld
     * recorder): the browser posts the file in pieces to the same place a
     * live recording's go, then finish() transcribes it like one. The file's
     * date is when it was saved — the end of the meeting — so the start is
     * placed once the length is known.
     *
     * @return array{id?: int, upload_url?: string, piece_bytes?: int, error?: string}
     */
    public function startUpload(bool $consent, string $name, ?string $mimeType, int $bytes, ?int $lastModifiedMs = null): array
    {
        $this->authorize('record-meetings');
        $this->authorize('update', $this->project);

        if (! $consent) {
            return ['error' => 'Confirm everyone agreed to be recorded first.'];
        }

        $maxMb = (int) config('meeting_recording.max_upload_mb', 1024);

        if ($bytes <= 0 || $bytes > $maxMb * 1024 * 1024) {
            return ['error' => sprintf('That file is %s — recordings up to %d MB can be uploaded.', $bytes <= 0 ? 'empty' : round($bytes / 1048576).' MB', $maxMb)];
        }

        $savedAt = $lastModifiedMs ? Carbon::createFromTimestampMs($lastModifiedMs) : null;

        if ($savedAt && ($savedAt->isFuture() || $savedAt->lt(now()->subDays(90)))) {
            $savedAt = null;
        }

        $recording = MeetingRecording::create([
            'belongs_to_vendor_id' => $this->project->belongs_to_vendor_id,
            'project_id' => $this->projectId,
            'task_id' => $this->taskId,
            'recorded_by_user_id' => auth()->id(),
            'status' => MeetingRecording::STATUS_RECORDING,
            'source' => MeetingRecording::SOURCE_UPLOAD,
            'consent_confirmed_at' => now(),
            'microphone' => mb_substr('Uploaded file · '.trim($name), 0, 250),
            'mime_type' => $mimeType ? mb_substr($mimeType, 0, 60) : null,
            'audio_disk' => config('meeting_recording.disk'),
            'started_at' => $savedAt,
            'ended_at' => $savedAt,
        ]);

        return [
            'id' => $recording->id,
            'upload_url' => route('meeting-recordings.chunks', $recording),
            'piece_bytes' => (int) config('meeting_recording.upload_piece_kb', 5120) * 1024,
        ];
    }

    /** Stop: the pieces are all posted; join, transcribe and summarize them. */
    public function finish(int $recordingId, int $chunks, int $seconds): void
    {
        $recording = $this->ownRecording($recordingId);

        if (! $recording) {
            return;
        }

        $endedAt = $recording->ended_at ?? now();

        $recording->update([
            'status' => MeetingRecording::STATUS_PROCESSING,
            'ended_at' => $endedAt,
            'started_at' => $recording->source === MeetingRecording::SOURCE_UPLOAD && $seconds > 0 ? $endedAt->copy()->subSeconds($seconds) : $recording->started_at,
            'duration_seconds' => max(0, $seconds),
            'chunk_count' => max($recording->chunk_count, $chunks),
        ]);

        PrepareMeetingRecording::dispatch($recording->id);
        unset($this->recordings);
    }

    /** Throw a recording away before it is processed (started by mistake). */
    public function discard(int $recordingId): void
    {
        $recording = $this->ownRecording($recordingId);

        if (! $recording) {
            return;
        }

        Storage::disk($recording->audio_disk ?: config('meeting_recording.disk'))->deleteDirectory('meeting-recordings/'.$recording->id);
        $recording->delete();
        unset($this->recordings);
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->findOrFail($this->projectId);
    }

    /**
     * This project's (or this Meet's) latest recordings, newest first.
     *
     * @return Collection<int, MeetingRecording>
     */
    #[Computed]
    public function recordings(): Collection
    {
        return MeetingRecording::query()
            ->with('recordedBy:id,first_name,last_name,nickname')
            ->where('project_id', $this->projectId)
            ->when($this->taskId, fn ($query) => $query->where('task_id', $this->taskId))
            ->where('status', '!=', MeetingRecording::STATUS_RECORDING)
            ->latest('id')
            ->limit(5)
            ->get();
    }

    /** Whether a recording is still being transcribed, so the list keeps checking. */
    #[Computed]
    public function processing(): bool
    {
        return $this->recordings->contains('status', MeetingRecording::STATUS_PROCESSING);
    }

    protected function ownRecording(int $recordingId): ?MeetingRecording
    {
        return MeetingRecording::query()
            ->whereKey($recordingId)
            ->where('recorded_by_user_id', auth()->id())
            ->where('project_id', $this->projectId)
            ->where('status', MeetingRecording::STATUS_RECORDING)
            ->first();
    }

    public function render(): View
    {
        return view('livewire.meetings.meeting-recorder');
    }
}
