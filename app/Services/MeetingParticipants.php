<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;

/**
 * Who a Meet task invites by default.
 *
 * One home for the rule, because the answer has to be the same however the
 * meeting was booked. It wasn't: the task form defaulted the client's people
 * in, while a consult booked straight from a lead email reply was created with
 * an empty participant list — so the homeowner never got the calendar invite
 * for their own consult.
 */
class MeetingParticipants
{
    /**
     * The team members meeting, the client's own people, and the sub being
     * scheduled — minus anything excluded().
     *
     * @param  array<int|string>  $userIds  team members assigned to the task
     * @return string[]
     */
    public static function defaults(?Project $project, array $userIds = [], ?int $vendorId = null): array
    {
        $emails = collect();

        $ids = collect($userIds)
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isNotEmpty()) {
            $emails = $emails->merge(User::query()->whereIn('id', $ids->all())->pluck('email'));
        }

        // The client's people — the homeowner. Placeholder addresses are
        // skipped; they route nowhere.
        if ($project) {
            $project->loadMissing('client.users');

            if ($project->client) {
                $emails = $emails->merge(
                    collect($project->client->users)
                        ->filter(fn (User $user) => $user->hasRoutableEmail())
                        ->pluck('email')
                );
            }
        }

        // The selected vendor's contact (the sub being scheduled).
        if (is_numeric($vendorId) && (int) $vendorId > 0) {
            $vendor = Vendor::withoutGlobalScopes()->find((int) $vendorId);
            $vendorEmail = trim((string) ($vendor?->email ?? $vendor?->business_email ?? ''));

            if ($vendorEmail !== '') {
                $emails->push($vendorEmail);
            }
        }

        return $emails
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn (string $email) => strtolower(trim($email)))
            ->reject(fn (string $email) => in_array($email, self::excluded($project), true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Never auto-invited: the owning company's own direct address (a generic
     * "crew@" inbox is not a meeting attendee).
     *
     * @return string[]
     */
    public static function excluded(?Project $project): array
    {
        $ownerVendorId = is_numeric($project?->belongs_to_vendor_id)
            ? (int) $project->belongs_to_vendor_id
            : null;

        if (! is_int($ownerVendorId) || $ownerVendorId <= 0) {
            return [];
        }

        $ownerVendor = Vendor::withoutGlobalScopes()->find($ownerVendorId);

        return collect([$ownerVendor?->email ?? null, $ownerVendor?->business_email ?? null])
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn (string $email) => strtolower(trim($email)))
            ->unique()
            ->values()
            ->all();
    }
}
