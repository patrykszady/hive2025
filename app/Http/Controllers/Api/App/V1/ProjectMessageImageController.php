<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\SendTextImagesRequest;
use App\Models\Project;
use App\Models\SmsGroupThread;
use App\Services\GroupSmsService;
use App\Services\Projects\TimelapseCollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The project Images page's Message Images card: photos texted about the
 * project, and texting project/message photos onward to a conversation.
 * Mirrors App\Livewire\Projects\TimelapseStudio's messageImages/
 * messageSenders computeds and its "Text Selected" modal
 * (openTextImagesModal/textImages), via the SAME
 * App\Services\Projects\TimelapseCollectionService methods.
 */
class ProjectMessageImageController extends Controller
{
    public function index(Request $request, int $project, TimelapseCollectionService $service): JsonResponse
    {
        $found = $this->visibleProject($project);
        Gate::authorize('view', $found);

        $images = $service->allMessageImages($found);
        $sender = $request->filled('sender') ? (string) $request->input('sender') : null;

        $senders = $images->groupBy('sender')->map->count()->sortDesc();

        // Same "a filter that matches nothing clears itself" rule as the
        // web (TimelapseStudio::messageImages()).
        if ($sender !== null) {
            $filtered = $images->where('sender', $sender)->values();

            if ($filtered->isNotEmpty()) {
                $images = $filtered;
            } else {
                $sender = null;
            }
        }

        return response()->json([
            'data' => $images->map(fn (array $image) => $this->imageArray($image))->values()->all(),
            'senders' => $senders->map(fn ($count, $name) => ['name' => $name, 'count' => $count])->values()->all(),
            'active_sender' => $sender,
        ]);
    }

    /** Conversations the selected photos could go to — the "Text Selected" modal's picker. */
    public function threads(Request $request, int $project, TimelapseCollectionService $service): JsonResponse
    {
        $found = $this->visibleProject($project);
        Gate::authorize('view', $found);

        $threads = $service->textableThreads((string) $request->input('q', ''));

        return response()->json([
            'data' => $threads->map(fn (SmsGroupThread $thread) => [
                'id' => $thread->id,
                'label' => $service->threadLabel($thread),
                'last_activity_at' => $thread->last_activity_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function send(SendTextImagesRequest $request, int $project, TimelapseCollectionService $service, GroupSmsService $sms): JsonResponse
    {
        $found = $this->visibleProject($project);
        Gate::authorize('view', $found);

        $frameIds = $request->validated('frame_ids') ?? [];
        $messageUrls = $request->validated('message_urls') ?? [];

        if (empty($frameIds) && empty($messageUrls)) {
            throw ValidationException::withMessages(['frame_ids' => 'Select at least one photo.']);
        }

        $threads = $service->textableThreads();
        $thread = $threads->firstWhere('id', (int) $request->validated('thread_id'));

        if (! $thread) {
            throw ValidationException::withMessages(['thread_id' => 'Pick a conversation.']);
        }

        $result = $service->sendTextImages(
            $found,
            $thread,
            $frameIds,
            $messageUrls,
            (string) $request->validated('note', ''),
            $request->user()?->id,
            $sms,
        );

        if (! $result['ok']) {
            $message = $result['reason'] === 'pending_opt_in'
                ? 'That conversation has participants who have not replied START yet.'
                : 'Those photos are not available on this server.';

            return response()->json(['message' => $message, 'reason' => $result['reason']], 422);
        }

        return response()->json(['data' => [
            'sent' => true,
            'count' => $result['count'],
            'thread_label' => $service->threadLabel($thread),
        ]]);
    }

    /** @return array<string, mixed> */
    private function imageArray(array $image): array
    {
        return [
            'url' => $image['url'],
            'thumb_url' => $image['thumb'],
            'raw' => $image['raw'],
            'sender' => $image['sender'],
            'label' => $image['label'],
            'sent_at' => $image['sent_at']->toIso8601String(),
        ];
    }

    /** A project the person may see: 404 when it doesn't exist, 403 when it isn't theirs. */
    private function visibleProject(int $id): Project
    {
        abort_unless(Project::withoutGlobalScopes()->whereKey($id)->exists(), 404);

        $project = Project::query()->whereKey($id)->first();
        abort_unless($project, 403, 'You do not have access to this project.');

        return $project;
    }
}
