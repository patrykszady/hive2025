<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Edits and deletes a single entry on a project's status history, exactly
 * like App\Livewire\ProjectStatus\StatusCreate::updateStatus()/deleteStatus()
 * — shared by that component and the Hive Contractors app's project page so
 * both write (and guard) the same row the same way.
 *
 * Ownership is "this status belongs to my vendor" — StatusCreate's own
 * checks additionally tried `$user->hasRole('Super Admin')` as a bypass, but
 * no User model in this app defines hasRole() at all, so that branch could
 * only ever be reached by throwing a fatal error instead of the intended
 * "Unauthorized" response; it is left out here (and in StatusCreate, which
 * now also calls this class) rather than carried over.
 */
class ProjectStatusHistoryWriter
{
    /** @throws AuthorizationException */
    public function assertOwnedBy(ProjectStatus $status, User $user): void
    {
        if ((int) $status->belongs_to_vendor_id !== (int) $user->vendor?->id) {
            throw new AuthorizationException('You cannot edit this status.');
        }
    }

    public function update(ProjectStatus $status, User $user, int $statusCode, string $startDate): ProjectStatus
    {
        $this->assertOwnedBy($status, $user);

        $status->update([
            'status_code' => $statusCode,
            'start_date' => $startDate,
        ]);

        return $status;
    }

    /** @throws ValidationException when it's the project's only status */
    public function delete(Project $project, ProjectStatus $status, User $user): void
    {
        if ($project->statuses()->count() <= 1) {
            throw ValidationException::withMessages([
                'status' => 'Project must have at least one status.',
            ]);
        }

        $this->assertOwnedBy($status, $user);

        $status->delete();
    }
}
