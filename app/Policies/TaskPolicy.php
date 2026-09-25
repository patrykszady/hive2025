<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return (bool) $user->vendor;
    }

    /**
     * Determine whether the user can view the model: a task on a project
     * this tenant can see (ProjectScope). Broader than update() — everyone
     * on a shared project can see every task on it, but only the task's own
     * company can change it.
     */
    public function view(User $user, Task $task): bool
    {
        return $user->vendor && Project::query()->whereKey($task->project_id)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return (bool) $user->vendor;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Task $task): bool
    {
        return $user->vendor->id === $task->belongs_to_vendor_id || $user->vendor->id === $task->vendor_id || in_array($task->created_by_user_id, $user->vendor->users()->employed()->pluck('users.id')->toArray());
    }

    /**
     * Determine whether the user can delete the model. Same tier as update:
     * a task on a shared project may be visible to every company on it, but
     * only the task's own company (owner, assigned sub, or its creator's
     * employer) may remove it.
     */
    public function delete(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }
}
