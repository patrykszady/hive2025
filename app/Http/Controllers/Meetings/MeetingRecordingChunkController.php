<?php

namespace App\Http\Controllers\Meetings;

use App\Http\Controllers\Controller;
use App\Models\MeetingRecording;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Receives one ~10-second piece of a meeting recording while it records, so
 * a dropped connection or a closed tab loses seconds, not the meeting. Only
 * the person recording can add to it, and only while it is still recording.
 */
class MeetingRecordingChunkController extends Controller
{
    public function __invoke(Request $request, MeetingRecording $meetingRecording): JsonResponse
    {
        abort_unless((int) $meetingRecording->recorded_by_user_id === (int) $request->user()->id, 403);
        abort_unless($meetingRecording->status === MeetingRecording::STATUS_RECORDING, 409, 'This recording has already stopped.');

        $validated = $request->validate([
            'seq' => ['required', 'integer', 'min:0', 'max:99999'],
            'chunk' => ['required', 'file', 'max:'.(int) config('meeting_recording.max_chunk_kb', 10240)],
        ]);

        Storage::disk($meetingRecording->audio_disk ?: config('meeting_recording.disk'))->putFileAs(
            $meetingRecording->chunkDirectory(),
            $request->file('chunk'),
            sprintf('%06d.part', $validated['seq']),
        );

        $meetingRecording->newQuery()->whereKey($meetingRecording->id)
            ->where('chunk_count', '<', $validated['seq'] + 1)
            ->update(['chunk_count' => $validated['seq'] + 1]);

        return response()->json(['ok' => true, 'seq' => $validated['seq']]);
    }
}
