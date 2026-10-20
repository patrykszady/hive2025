<?php

namespace App\Services\Emails;

use App\Jobs\ExtractProjectActionItems;
use App\Models\EmailMessage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Sorting the unplaced inbox: an admin puts an email the AI could not place
 * on a project (it then joins that project's timeline and its action items
 * feed the project's list), or dismisses it as not about any project. Shared
 * by the web page and the Hive Contractors app.
 */
class EmailPlacement
{
    /** Projects the picker offers beyond the email's own candidates: the open pipeline. */
    public const OPEN_STATUS_CODES = [1, 9, 2, 3, 4, 5, 6, 8];

    /**
     * The email's candidate projects (the stakeholder's own), newest first.
     *
     * @return Collection<int, Project>
     */
    public function candidates(EmailMessage $email): Collection
    {
        $ids = array_map('intval', (array) ($email->candidate_project_ids ?? []));

        if ($ids === []) {
            return collect();
        }

        return Project::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->where('belongs_to_vendor_id', $email->belongs_to_vendor_id)
            ->orderByDesc('id')
            ->get(['id', 'project_name', 'address', 'city', 'client_id', 'belongs_to_vendor_id']);
    }

    /**
     * Every open project of the company, for "another project".
     *
     * @return Collection<int, Project>
     */
    public function openProjects(int $vendorId): Collection
    {
        return Project::withoutGlobalScopes()
            ->where('belongs_to_vendor_id', $vendorId)
            ->with(['latestStatus' => fn ($query) => $query->withoutGlobalScopes()])
            ->orderBy('project_name')
            ->get(['id', 'project_name', 'address', 'city', 'client_id', 'belongs_to_vendor_id'])
            ->filter(fn (Project $project) => in_array((int) $project->latestStatus?->status_code, self::OPEN_STATUS_CODES, true))
            ->values();
    }

    /**
     * Put the email on a project of the same company. Action items it
     * carries go to that project's list.
     */
    public function place(EmailMessage $email, int $projectId, User $by): EmailMessage
    {
        $project = Project::withoutGlobalScopes()
            ->whereKey($projectId)
            ->where('belongs_to_vendor_id', $email->belongs_to_vendor_id)
            ->first();

        if (! $project || ! $by->can('update', $project)) {
            throw ValidationException::withMessages(['project_id' => 'Choose one of your company\'s projects.']);
        }

        $email->forceFill(['project_id' => $project->id, 'unplaced_dismissed_at' => null])->save();

        if ((array) $email->action_items !== []) {
            ExtractProjectActionItems::dispatch($project->id)->delay(now()->addMinute());
        }

        return $email;
    }

    /** Not about any project: out of the inbox, off every timeline. */
    public function dismiss(EmailMessage $email): EmailMessage
    {
        $email->forceFill(['unplaced_dismissed_at' => now()])->save();

        return $email;
    }

    /** Whether the person sorts this company's email: an Admin of it. */
    public static function canSort(User $user, int $vendorId): bool
    {
        return $user->getRoleForVendor($vendorId) === 'Admin';
    }
}
