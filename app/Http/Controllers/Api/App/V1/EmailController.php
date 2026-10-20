<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\PlaceEmailRequest;
use App\Http\Resources\Api\App\EmailResource;
use App\Models\EmailMessage;
use App\Models\Project;
use App\Services\Emails\EmailPlacement;
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
    /** Mirrors UnplacedEmails' own 25/page (WithPagination). */
    private const PER_PAGE = 25;

    public function show(Request $request, int $email): JsonResponse
    {
        $found = EmailMessage::withoutGlobalScopes()->whereKey($email)->first();
        abort_unless($found, 404);

        $user = $request->user();
        abort_unless($found->belongs_to_vendor_id === (int) $user->vendor?->id, 403, 'You do not have access to this email.');
        abort_unless(
            $this->canViewEmailProject($found) || ($found->project_id === null && EmailPlacement::canSort($user, $found->belongs_to_vendor_id)),
            403,
            'You do not have access to this email.',
        );

        return response()->json(['data' => (new EmailResource($found))->toArray($request)]);
    }

    /**
     * The unplaced inbox: emails the AI read but could not put on a project,
     * for an Admin to sort. Newest first, 25/page — same page size as the
     * web's UnplacedEmails (WithPagination), cursor-paginated like the
     * Notifications tab so a company with more than one page can page
     * through every one of them instead of only ever seeing the newest 100.
     */
    public function unplaced(Request $request, EmailPlacement $placement): JsonResponse
    {
        $user = $request->user();
        $vendorId = (int) $user->vendor?->id;
        abort_unless($vendorId && EmailPlacement::canSort($user, $vendorId), 403);

        $page = EmailMessage::query()
            ->where('belongs_to_vendor_id', $vendorId)
            ->unplaced()
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (EmailMessage $email) => [
                'id' => $email->id,
                'direction' => $email->direction,
                'subject' => $email->subject,
                'from' => ['email' => $email->from_email, 'name' => $email->from_name],
                'sent_at' => $email->sent_at->toIso8601String(),
                'summary' => $email->summary,
                'snippet' => $email->snippet,
                'attachment_count' => count((array) $email->attachments),
                'candidates' => EmailResource::candidatesFor($email, $placement),
            ])->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function place(PlaceEmailRequest $request, int $email, EmailPlacement $placement): JsonResponse
    {
        $found = $this->sortable($request, $email);

        $placement->place($found, (int) $request->validated('project_id'), $request->user());

        return response()->json(['data' => (new EmailResource($found->fresh()))->toArray($request)]);
    }

    public function dismiss(Request $request, int $email, EmailPlacement $placement): JsonResponse
    {
        $found = $this->sortable($request, $email);

        $placement->dismiss($found);

        return response()->json(['data' => ['id' => $found->id, 'dismissed' => true]]);
    }

    /** An email of the viewer's company that they, an Admin, may sort. */
    private function sortable(Request $request, int $email): EmailMessage
    {
        $found = EmailMessage::withoutGlobalScopes()->whereKey($email)->first();
        abort_unless($found, 404);

        $user = $request->user();
        abort_unless(
            $found->belongs_to_vendor_id === (int) $user->vendor?->id && EmailPlacement::canSort($user, $found->belongs_to_vendor_id),
            403,
            'You do not have access to this email.',
        );

        return $found;
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
                && Gate::allows('update', $project);
        }

        if (! $email->client_id) {
            return false;
        }

        return Project::query()
            ->where('client_id', $email->client_id)
            ->get()
            ->contains(fn (Project $candidate) => Gate::allows('update', $candidate));
    }
}
