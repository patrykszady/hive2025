<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An in-person meeting recorded with Hive's Record button: the browser sends
 * the audio in pieces while it records (recording), and once it stops the
 * pieces are joined, transcribed and summarized (processing → ready). A
 * recording made elsewhere (Voice Memos on the phone, a handheld recorder)
 * takes the same road as an uploaded file (source upload). Its
 * action items reach the project's planner the way a call's do
 * (ProjectActionItemExtractor reads them), and it shows in the project's
 * communications timeline.
 *
 * Illinois needs everyone's consent to record a private conversation, so a
 * recording can't start without consent_confirmed_at.
 */
class MeetingRecording extends Model
{
    use HasFactory;

    public const STATUS_RECORDING = 'recording';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    /** Nothing anyone said: silence, or too short to be a meeting. */
    public const STATUS_EMPTY = 'empty';

    public const STATUS_FAILED = 'failed';

    /** Recorded live in the browser with the Record button. */
    public const SOURCE_BROWSER = 'browser';

    /** A file recorded elsewhere (Voice Memos, a handheld recorder) and uploaded afterwards. */
    public const SOURCE_UPLOAD = 'upload';

    protected $fillable = [
        'belongs_to_vendor_id', 'project_id', 'task_id', 'recorded_by_user_id', 'status', 'source',
        'consent_confirmed_at', 'microphone', 'started_at', 'ended_at', 'duration_seconds',
        'chunk_count', 'mime_type', 'audio_disk', 'audio_path', 'language', 'transcript',
        'segments', 'summary', 'action_items', 'topics', 'next_steps', 'summary_model',
        'summarized_at', 'assemblyai_id', 'failure_reason', 'purge_after',
    ];

    protected function casts(): array
    {
        return [
            'consent_confirmed_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'summarized_at' => 'datetime',
            'purge_after' => 'datetime',
            'duration_seconds' => 'integer',
            'chunk_count' => 'integer',
            'segments' => 'array',
            'action_items' => 'array',
            'topics' => 'array',
            'next_steps' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** Where the audio pieces for this recording are kept while it records. */
    public function chunkDirectory(): string
    {
        return 'meeting-recordings/'.$this->id.'/chunks';
    }

    /** The extension the audio is saved under before it is compressed (ffmpeg reads the content either way). */
    public function audioExtension(): string
    {
        $mime = (string) $this->mime_type;

        return match (true) {
            str_contains($mime, 'mp4') || str_contains($mime, 'm4a') || str_contains($mime, 'aac') => 'm4a',
            str_contains($mime, 'mpeg') || str_contains($mime, 'mp3') => 'mp3',
            str_contains($mime, 'wav') => 'wav',
            str_contains($mime, 'ogg') => 'ogg',
            default => 'webm',
        };
    }
}
