<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\App\EmailResource;
use App\Models\EmailMessage;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * One stakeholder email's full detail, tapped from the project
 * communications timeline — see ProjectCommunicationsController and
 * EmailResource. Visible only when the email belongs to the viewer's
 * vendor AND the viewer can see the project it's about — or, when it has
 * no project yet, any project of its client.
 */
class EmailController extends Controller
{
    public function show(Request $request, int $email): JsonResponse
    {
        $found = EmailMessage::withoutGlobalScopes()->whereKey($email)->first();
        abort_unless($found, 404);

        $user = $request->user();
        abort_unless($found->belongs_to_vendor_id === (int) $user->vendor?->id, 403, 'You do not have access to this email.');
        abort_unless($this->canViewEmailProject($found), 403, 'You do not have access to this email.');

        return response()->json(['data' => (new EmailResource($found))->toArray($request)]);
    }

    /**
     * The email's own project when it has one (whether or not it's still
     * candidate-matched — a stakeholder email can be filed before anyone
     * reviews it), otherwise any of its client's projects the viewer can
     * see — same "no project yet" allowance as
     * EmailMessage::scopeForProject().
     */
    private function canViewEmailProject(EmailMessage $email): bool
    {
        if ($email->project_id) {
            $project = Project::withoutGlobalScopes()->whereKey($email->project_id)->first();

            return $project
                && Project::query()->whereKey($project->id)->exists()
                && Gate::allows('view', $project);
        }

        if (! $email->client_id) {
            return false;
        }

        return Project::query()
            ->where('client_id', $email->client_id)
            ->get()
            ->contains(fn (Project $candidate) => Gate::allows('view', $candidate));
    }
}
