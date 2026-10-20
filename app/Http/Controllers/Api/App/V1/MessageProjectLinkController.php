<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\ResolvesAppThread;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\AddMessagePhotosToProjectRequest;
use App\Models\Project;
use App\Models\SmsMessage;
use App\Services\Sms\MessagePhotoProjectLinker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Add to Project" for a message's photos —
 * App\Livewire\Sms\SmsConversation::openAddToProjectModal()/addableProjects()/
 * addImagesToProject(). Pins the message to the project; nothing is copied.
 */
class MessageProjectLinkController extends Controller
{
    use ResolvesAppThread;

    /** Candidate projects to pin this message's photos to. */
    public function candidates(Request $request, int $thread, int $message, MessagePhotoProjectLinker $linker): JsonResponse
    {
        $this->resolveAppThread($thread, $request->user());

        $smsMessage = SmsMessage::where('thread_id', $thread)->whereKey($message)->firstOrFail();

        if ($linker->imageUrlsFor($smsMessage)->isEmpty()) {
            return response()->json(['message' => 'That message has no photos to add.'], 422);
        }

        $asOf = $smsMessage->created_at?->toDateTimeString() ?? now()->toDateTimeString();
        $projects = $linker->candidateProjects(
            $smsMessage->thread?->client_id,
            $asOf,
            (string) $request->query('search', ''),
        );

        return response()->json(['data' => $projects->map(fn (Project $project) => [
            'id' => $project->id,
            'address' => $project->short_address ?? $project->address,
            'client_name' => $project->client?->name,
            'default' => (int) $project->id === (int) ($smsMessage->thread?->project_id ?? 0),
        ])->values()]);
    }

    public function store(AddMessagePhotosToProjectRequest $request, int $thread, int $message, MessagePhotoProjectLinker $linker): JsonResponse
    {
        $user = $request->user();
        $this->resolveAppThread($thread, $user);

        $smsMessage = SmsMessage::where('thread_id', $thread)->whereKey($message)->firstOrFail();

        if ($linker->imageUrlsFor($smsMessage)->isEmpty()) {
            return response()->json(['message' => 'That message has no photos to add.'], 422);
        }

        // Global ProjectScope limits this to the user's vendor — an id from
        // outside it simply doesn't resolve, same as the web.
        $project = Project::find($request->validated('project_id'));

        if (! $project) {
            return response()->json(['message' => 'Pick a project.'], 422);
        }

        $added = $linker->attach($project, collect([$smsMessage]), (int) $user->id);

        return response()->json([
            'added' => $added,
            'project' => ['id' => $project->id, 'address' => $project->short_address ?? $project->address],
        ]);
    }
}
