<?php

namespace App\Services\Sms;

use App\Models\Project;
use App\Models\SmsMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Add to Project" for a message's photos — shared by the web conversation
 * (App\Livewire\Sms\SmsConversation::addableProjects()/addImagesToProject())
 * and the app's add-to-project endpoint. Pins the message to the project
 * (project_pinned_messages) rather than copying anything: the photos stay in
 * the message and surface under the project's Message Images, the same way
 * this thread already shows them.
 */
class MessagePhotoProjectLinker
{
    /** Image entries on a message — the things worth copying to a project. */
    public function imageUrlsFor(SmsMessage $message): Collection
    {
        return collect(is_array($message->media_urls) ? $message->media_urls : [])
            ->filter(fn ($url) => is_string($url)
                && preg_match('/\.(jpe?g|png|heic|webp|gif)$/i', $url) === 1)
            ->values();
    }

    /**
     * Projects the photos could belong to: the thread's client's projects
     * first (that's who texted them), then whichever were most recently
     * active AS OF THE DAY the photos were messaged — mirrors
     * SmsConversation::addableProjects() exactly.
     */
    public function candidateProjects(?int $clientId, string $asOf, string $search = ''): Collection
    {
        // MySQL's UNIX_TIMESTAMP() (what the web's addableProjects() calls)
        // has no SQLite equivalent, written per-driver so this also runs on
        // the Pest suite's SQLite — same pattern as
        // App\Services\Vendors\AppVendorCategorization::availableYears().
        $unixTimestamp = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%s', updated_at)"
            : 'UNIX_TIMESTAMP(updated_at)';

        return Project::query()
            ->with(['client', 'latestStatus'])
            ->when(trim($search) !== '', function ($q) use ($search) {
                $term = '%'.trim($search).'%';
                $q->where(fn ($inner) => $inner->where('address', 'like', $term)
                    ->orWhere('project_name', 'like', $term));
            })
            ->when($clientId, fn ($q) => $q->orderByRaw('CASE WHEN client_id = ? THEN 0 ELSE 1 END', [$clientId]))
            // Projects that were ACTIVE (status 6) on the day the photos were
            // messaged lead — the crew was standing on one of those.
            ->orderByRaw(
                'CASE WHEN (SELECT ps.status_code FROM project_status ps
                    WHERE ps.project_id = projects.id AND ps.start_date <= ?
                    ORDER BY ps.start_date DESC LIMIT 1) = 6 THEN 0 ELSE 1 END',
                [$asOf]
            )
            // Then touched before the message date → newest of those first;
            // only then projects that didn't exist or move until later.
            ->orderByRaw('CASE WHEN updated_at <= ? THEN 0 ELSE 1 END', [$asOf])
            ->orderByRaw("CASE WHEN updated_at <= ? THEN -{$unixTimestamp} ELSE {$unixTimestamp} END", [$asOf])
            ->limit(60)
            ->get();
    }

    /**
     * Pin each message's photos onto the project. Returns how many photos
     * were newly added (a message already pinned there contributes zero).
     *
     * @param  Collection<int, SmsMessage>  $messages
     */
    public function attach(Project $project, Collection $messages, int $actorId): int
    {
        $added = 0;

        foreach ($messages as $message) {
            $photos = $this->imageUrlsFor($message)->count();

            if ($photos === 0) {
                continue;
            }

            $pinned = DB::table('project_pinned_messages')->insertOrIgnore([
                'project_id' => $project->id,
                'sms_message_id' => $message->id,
                'added_by_user_id' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($pinned) {
                $added += $photos;
            }
        }

        return $added;
    }
}
