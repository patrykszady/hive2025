<?php

namespace App\Services\Hours;

use App\Models\Hour;
use App\Models\Project;
use App\Models\User;

/**
 * Saves a user's logged hours for one date across one or more projects.
 * Shared by the web's HourForm (Livewire — HourCreate's "Add"/"Edit Daily
 * Hours" actions) and the app's HourController, so logging hours behaves
 * identically from either client: a max of 16 hours/project/day, a project
 * outside the tenant is silently dropped rather than trusted, and zeroing an
 * existing entry deletes it.
 */
class HourEntryWriter
{
    /**
     * project_id rides in on caller-controlled input — check it against what
     * this tenant can see (ProjectScope, applied automatically) rather than
     * trusting it, so hours can't be logged against a project outside the
     * tenant.
     */
    public function isProjectAccessible(mixed $projectId): bool
    {
        return (bool) $projectId && Project::whereKey($projectId)->exists();
    }

    /**
     * New entries only — mirrors HourForm::store(): every project with
     * hours > 0 against an accessible project becomes a new Hour row;
     * anything else (null/zero hours, or an inaccessible project) is
     * skipped.
     *
     * @param  iterable<array{id: mixed, hours: mixed}>  $projects
     */
    public function store(User $user, mixed $date, iterable $projects): void
    {
        $withHours = collect($projects)
            ->where('hours', '!=', null)
            ->where('hours', '>', 0)
            ->filter(fn (array $project) => $this->isProjectAccessible($project['id'] ?? null));

        foreach ($withHours as $project) {
            Hour::create([
                'date' => $date,
                'hours' => $project['hours'],
                'project_id' => $project['id'],
                'user_id' => $user->id,
                'vendor_id' => $user->vendor->id,
                'created_by_user_id' => $user->id,
            ]);
        }
    }

    /**
     * Updates an existing day's entries — mirrors HourForm::update(): a
     * project carrying an existing `hour_id` is updated (or deleted, when its
     * hours end up null/zero); a project with no `hour_id` but positive hours
     * against an accessible project becomes a new row.
     *
     * @param  iterable<array{id: mixed, hours: mixed, hour_id?: int}>  $projects
     */
    public function update(User $user, mixed $date, iterable $projects): void
    {
        // Not collect()->where('hours', '!=', null): PHP's loose `!=`
        // treats 0 == null, which would silently drop every entry an API
        // client zeroes out as a true int/float 0 (a web <input> only
        // avoided this by accident, always posting it as the string "0").
        // array_key_exists + a strict null check keeps an explicit 0 (of any
        // type) in the batch, which the delete check just below relies on.
        $withHours = collect($projects)->filter(
            fn (array $project) => array_key_exists('hours', $project) && $project['hours'] !== null
        );

        foreach ($withHours as $project) {
            if (isset($project['hour_id'])) {
                $hour = Hour::findOrFail($project['hour_id']);

                if ($project['hours'] == null || $project['hours'] == 0) {
                    $hour->delete();
                } elseif ($this->isProjectAccessible($project['id'] ?? null)) {
                    $hour->update([
                        'hours' => $project['hours'],
                        'project_id' => $project['id'],
                    ]);
                }
            } elseif (isset($project['hours']) && $project['hours'] > 0 && $this->isProjectAccessible($project['id'] ?? null)) {
                Hour::create([
                    'date' => $date,
                    'hours' => $project['hours'],
                    'project_id' => $project['id'],
                    'user_id' => $user->id,
                    'vendor_id' => $user->vendor->id,
                    'created_by_user_id' => $user->id,
                ]);
            }
        }
    }
}
