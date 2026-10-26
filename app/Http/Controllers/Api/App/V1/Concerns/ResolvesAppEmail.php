<?php

namespace App\Http\Controllers\Api\App\V1\Concerns;

use App\Models\EmailMessage;
use App\Models\Project;
use App\Models\User;
use App\Services\Emails\EmailPlacement;
use Illuminate\Support\Facades\Gate;

/**
 * Who may open a stakeholder email in the app — shared by the email screen
 * (EmailController) and "Create Task" from an email
 * (MessageTaskSuggestionController), the way ResolvesAppThread is for texts.
 */
trait ResolvesAppEmail
{
    /**
     * The email for this id, if it belongs to the viewer's company and they
     * can see the project it's about — or, unplaced, they may sort it.
     */
    protected function resolveAppEmail(int $emailId, User $user): EmailMessage
    {
        $found = EmailMessage::withoutGlobalScopes()->whereKey($emailId)->first();
        abort_unless($found, 404);

        abort_unless($found->belongs_to_vendor_id === (int) $user->vendor?->id, 403, 'You do not have access to this email.');
        abort_unless(
            $this->canViewEmailProject($found) || ($found->project_id === null && EmailPlacement::canSort($user, $found->belongs_to_vendor_id)),
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
    protected function canViewEmailProject(EmailMessage $email): bool
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
