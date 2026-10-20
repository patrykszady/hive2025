<?php

namespace App\Services\Projects;

use App\Exceptions\Projects\DuplicateCollectionTitleException;
use App\Jobs\AlignTimelapseFrame;
use App\Livewire\Projects\TimelapseStudio;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\GroupSmsService;
use App\Services\ProjectImageImporter;
use App\Support\ImageThumbs;
use App\Support\Sms\ConversationPresenter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The project images page's shared logic — every read and write
 * App\Livewire\Projects\TimelapseStudio needs, extracted here so the Hive
 * Contractors app's project images API (App\Http\Controllers\Api\App\V1\
 * ProjectCollectionController / ProjectMessageImageController) calls the
 * SAME code rather than a parallel copy. TimelapseStudio keeps every public
 * method/property signature it had before this existed (and the Livewire
 * tests that depend on them) — its methods now mostly delegate to the
 * methods here, wiring the result into toasts/error bags/dispatches the app
 * doesn't have.
 *
 * Authorization is checked HERE for every mutating method (Gate::authorize,
 * which throws the same 403 whether the caller is a Livewire action or an
 * app controller) — TimelapseStudio also keeps its own pre-existing
 * `$this->authorize(...)` call at the top of each method it already had one
 * in, so neither path can end up less strict than before.
 */
class TimelapseCollectionService
{
    /**
     * Every collection on a project, each with its frames in play order —
     * the catch-all album first, then every sequence/album in its own
     * sort order. Both sides (frame->timelapse and collection->project) are
     * set in memory so archiveVisibleTo() and lightboxFrames() never
     * lazy-load them.
     *
     * @return Collection<int, ProjectTimelapse>
     */
    public function collectionsFor(Project $project): Collection
    {
        return ProjectTimelapse::query()
            ->where('project_id', $project->id)
            ->with(['frames' => fn ($q) => $q->with('takenBy:id,first_name,nickname')
                ->orderBy('sort_order')->orderBy('id')])
            ->orderByRaw("CASE WHEN title = 'Project Images' THEN 0 ELSE 1 END")
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->each(function (ProjectTimelapse $collection) use ($project) {
                $collection->setRelation('project', $project);
                $collection->frames->each(fn ($frame) => $frame->setRelation('timelapse', $collection));
            });
    }

    /**
     * Start another timelapse or photo album on this project. Anyone who
     * may view the project may create one — curating what's IN it
     * (reorder/delete/anchor) is the narrower manageImages gate.
     *
     * @throws DuplicateCollectionTitleException
     */
    public function createCollection(Project $project, string $title): ProjectTimelapse
    {
        Gate::authorize('view', $project);

        $title = trim($title);

        if (ProjectTimelapse::where('project_id', $project->id)->where('title', $title)->exists()) {
            throw new DuplicateCollectionTitleException($title);
        }

        return ProjectTimelapse::create([
            'project_id' => $project->id,
            'title' => $title,
            'kind' => ProjectTimelapse::KIND_TIMELAPSE,
            // display_mode belongs to gs.construction's renderer — see the
            // identical note on TimelapseStudio::createCollection() before
            // this moved here.
            'display_mode' => 'slider',
            'sort_order' => ((int) ProjectTimelapse::where('project_id', $project->id)->max('sort_order')) + 1,
        ]);
    }

    /**
     * Build a new timelapse from photos picked in select mode — copies, in
     * shot order, never moves. See TimelapseStudio::createTimelapseFromSelection()
     * for the full reasoning; this is that method's body, parameterized.
     *
     * @param  array<int, int|string>  $frameIds
     * @return array{ok: bool, reason: ?string, timelapse: ?ProjectTimelapse, title: ?string, count: int}
     */
    public function createTimelapseFromSelection(Project $project, array $frameIds): array
    {
        Gate::authorize('manageImages', $project);

        $frames = ProjectTimelapseFrame::query()
            ->whereIn('id', array_map('intval', $frameIds))
            ->whereHas('timelapse', fn ($q) => $q->where('project_id', $project->id))
            ->get()
            ->sortBy(fn (ProjectTimelapseFrame $f) => [$f->shot_at?->timestamp ?? 0, $f->id])
            ->values();

        if ($frames->count() < 2) {
            return ['ok' => false, 'reason' => 'too_few', 'timelapse' => null, 'title' => null, 'count' => 0];
        }

        $title = $this->uniqueCollectionTitle($project, 'Timelapse');

        $timelapse = ProjectTimelapse::create([
            'project_id' => $project->id,
            'title' => $title,
            'kind' => ProjectTimelapse::KIND_TIMELAPSE,
            'display_mode' => 'slider',
            'sort_order' => ((int) ProjectTimelapse::where('project_id', $project->id)->max('sort_order')) + 1,
        ]);

        $disk = Storage::disk('files');
        $copied = [];

        foreach ($frames as $position => $source) {
            $filename = Str::uuid().'.jpg';
            $directory = sprintf('timelapse/%d', $project->id);
            $path = sprintf('%s/%s', $directory, $filename);

            if (! $disk->exists($source->path) || ! $disk->copy($source->path, $path)) {
                continue;
            }

            $copied[] = ProjectTimelapseFrame::create([
                'project_timelapse_id' => $timelapse->id,
                'taken_by_user_id' => $source->taken_by_user_id,
                'taken_by_name' => $source->taken_by_name,
                'filename' => $filename,
                'original_filename' => $source->original_filename,
                'path' => $path,
                'original_path' => $source->original_path,
                'disk' => 'files',
                'shot_at' => $source->shot_at,
                'latitude' => $source->latitude,
                'longitude' => $source->longitude,
                'location_accuracy' => $source->location_accuracy,
                'sort_order' => $position + 1,
            ]);
        }

        if (count($copied) < 2) {
            $timelapse->forceDelete();

            return ['ok' => false, 'reason' => 'copy_failed', 'timelapse' => null, 'title' => null, 'count' => 0];
        }

        $timelapse->forceFill(['anchor_frame_id' => $copied[0]->id])->save();

        Bus::chain(
            collect($copied)->skip(1)
                ->map(fn (ProjectTimelapseFrame $f) => new AlignTimelapseFrame($f->id, reframe: true))
                ->all()
        )->onQueue('timelapse')->dispatch();

        return ['ok' => true, 'reason' => null, 'timelapse' => $timelapse, 'title' => $title, 'count' => count($copied)];
    }

    /** "Timelapse", "Timelapse 2", … — whatever this project doesn't have yet. */
    private function uniqueCollectionTitle(Project $project, string $base): string
    {
        $taken = ProjectTimelapse::withTrashed()
            ->where('project_id', $project->id)
            ->pluck('title')
            ->all();

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        for ($n = 2; $n < 200; $n++) {
            if (! in_array("{$base} {$n}", $taken, true)) {
                return "{$base} {$n}";
            }
        }

        return $base.' '.now()->format('M j H:i');
    }

    /**
     * Soft-delete a collection — frames keep their rows and files untouched,
     * so restoring the collection is one row. The catch-all album can never
     * be deleted (403): an unaimed upload would have nowhere to go.
     */
    public function deleteCollection(Project $project, int $collectionId): ProjectTimelapse
    {
        Gate::authorize('manageImages', $project);

        $collection = ProjectTimelapse::query()
            ->where('project_id', $project->id)
            ->findOrFail($collectionId);

        abort_if($collection->title === 'Project Images', 403);

        $collection->delete();

        return $collection;
    }

    /**
     * Move a frame one slot earlier (-1) or later (+1) in its sequence,
     * renumbering sort orders 1..n on the way.
     */
    public function moveFrame(Project $project, int $frameId, int $direction): void
    {
        Gate::authorize('manageImages', $project);
        abort_unless(in_array($direction, [-1, 1], true), 422);

        $frame = ProjectTimelapseFrame::query()
            ->whereHas('timelapse', fn ($q) => $q->where('project_id', $project->id))
            ->findOrFail($frameId);

        $ordered = ProjectTimelapseFrame::query()
            ->where('project_timelapse_id', $frame->project_timelapse_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->values();

        $index = $ordered->search(fn ($f) => $f->id === $frame->id);
        $target = $index + $direction;

        if ($target < 0 || $target >= $ordered->count()) {
            return;
        }

        $list = $ordered->all();
        [$list[$index], $list[$target]] = [$list[$target], $list[$index]];

        foreach ($list as $position => $sibling) {
            if ($sibling->sort_order !== $position + 1) {
                $sibling->forceFill(['sort_order' => $position + 1])->save();
            }
        }
    }

    /** The taker can remove their own bad frame on an ALBUM; Admins can remove any. */
    public function deleteFrame(Project $project, int $frameId): void
    {
        $frame = ProjectTimelapseFrame::query()
            ->whereHas('timelapse', fn ($q) => $q->where('project_id', $project->id))
            ->findOrFail($frameId);

        Gate::authorize('manageImages', $project);

        $frame->delete();
    }

    /**
     * Photos texted about this project, newest first — every image on a
     * thread tied to the project or to its client, plus anything pinned
     * here via "Add to Project". See TimelapseStudio::messageImages() for
     * the sender-filter wrapper around this (unfiltered) list.
     *
     * @return Collection<int, array{url:string, thumb:string, micro:?string, raw:string, sent_at:\Illuminate\Support\Carbon, sender:string, label:string}>
     */
    public function allMessageImages(Project $project): Collection
    {
        $clientId = $project->client_id;

        // client_id can be shared across vendors (a homeowner working with
        // more than one company) — accessibleTo() keeps a thread that
        // happens to share this project's client_id, but actually belongs
        // to a DIFFERENT vendor's conversation, from leaking onto this page.
        $threadIds = SmsGroupThread::query()
            ->accessibleTo(auth()->user())
            ->where(fn ($q) => $q->where('project_id', $project->id)
                ->when($clientId, fn ($inner) => $inner->orWhere('client_id', $clientId)))
            ->pluck('id');

        $pinnedIds = DB::table('project_pinned_messages')
            ->where('project_id', $project->id)
            ->pluck('sms_message_id');

        if ($threadIds->isEmpty() && $pinnedIds->isEmpty()) {
            return collect();
        }

        $messages = SmsMessage::query()
            ->withoutGlobalScopes()
            ->where(function ($q) use ($threadIds, $pinnedIds) {
                if ($threadIds->isNotEmpty()) {
                    $q->whereIn('thread_id', $threadIds);
                }

                if ($pinnedIds->isNotEmpty()) {
                    $threadIds->isNotEmpty()
                        ? $q->orWhereIn('id', $pinnedIds)
                        : $q->whereIn('id', $pinnedIds);
                }
            })
            ->whereNotNull('media_urls')
            ->latest('created_at')
            ->limit(120)
            ->get(['id', 'direction', 'media_urls', 'created_at', 'from_number', 'sent_by_user_id', 'text']);

        $senders = TimelapseStudio::firstNames($this->senderNames($messages, $project));
        $tz = $this->vendorTimezone($project);

        return $messages
            ->flatMap(function ($message) use ($senders, $tz) {
                $sender = $senders[$message->id] ?? ($message->direction === 'inbound' ? 'Client' : 'GS Construction');

                return collect($message->media_urls ?? [])
                    ->filter(fn ($url) => is_string($url)
                        && preg_match('/\.(jpe?g|png|heic|webp|gif)$/i', $url) === 1)
                    ->map(fn ($url) => [
                        'url' => ConversationPresenter::mediaUrl($url),
                        'thumb' => ConversationPresenter::mediaUrl($url, thumb: true),
                        'micro' => $this->smsMicro($url),
                        'raw' => $url,
                        'sent_at' => $message->created_at->copy()->timezone($tz),
                        'sender' => $sender,
                        'label' => $sender.' · '.$message->created_at->copy()->timezone($tz)->format('n/j/y'),
                    ]);
            })
            ->values();
    }

    /**
     * Who sent each message, keyed by message id — ported verbatim from
     * TimelapseStudio::senderNames().
     *
     * @param  Collection<int, SmsMessage>  $messages
     * @return array<int, string>
     */
    private function senderNames(Collection $messages, Project $project): array
    {
        $userIds = $messages->pluck('sent_by_user_id')->filter()->unique();

        $users = $userIds->isEmpty()
            ? collect()
            : User::withoutGlobalScopes()
                ->whereIn('id', $userIds)
                ->get(['id', 'first_name', 'last_name', 'nickname'])
                ->keyBy('id');

        $digits = $messages
            ->filter(fn ($m) => $m->direction === 'inbound')
            ->map(fn ($m) => substr(preg_replace('/\D/', '', (string) $m->from_number) ?? '', -10))
            ->filter()
            ->unique();

        $byPhone = $digits->isEmpty()
            ? collect()
            : User::withoutGlobalScopes()
                ->where(function ($query) use ($digits) {
                    foreach ($digits as $ten) {
                        $query->orWhere('cell_phone', $ten)->orWhere('cell_phone', '1'.$ten);
                    }
                })
                ->get(['id', 'first_name', 'last_name', 'nickname', 'cell_phone'])
                ->keyBy(fn ($user) => substr(preg_replace('/\D/', '', (string) $user->cell_phone) ?? '', -10));

        $name = fn ($user) => trim(($user->nickname ?: $user->first_name).' '.($user->last_name ?? '')) ?: 'Unknown';

        // Outbound sent before the app stamped sent_by_user_id: the crew
        // signs texts "-PS" / "-GS" — see TimelapseStudio::signatureInitials().
        $sigs = $messages
            ->filter(fn ($m) => $m->direction !== 'inbound' && ! $m->sent_by_user_id)
            ->map(fn ($m) => self::signatureInitials((string) $m->text))
            ->filter()
            ->unique();

        $bySignature = $sigs->isEmpty()
            ? collect()
            : User::withoutGlobalScopes()
                ->join('user_vendor', 'user_vendor.user_id', '=', 'users.id')
                ->where('user_vendor.vendor_id', $project->belongs_to_vendor_id)
                ->get(['users.id', 'users.first_name', 'users.last_name', 'users.nickname'])
                ->unique('id')
                ->keyBy(fn ($u) => strtoupper(mb_substr((string) $u->first_name, 0, 1).mb_substr((string) $u->last_name, 0, 1)));

        return $messages->mapWithKeys(function ($message) use ($users, $byPhone, $bySignature, $name) {
            if ($message->direction !== 'inbound') {
                if ($message->sent_by_user_id) {
                    $user = $users->get($message->sent_by_user_id);

                    return [$message->id => $user ? $name($user) : 'GS Construction'];
                }

                $sig = self::signatureInitials((string) $message->text);
                $user = $sig ? $bySignature->get($sig) : null;

                return [$message->id => $user ? $name($user) : 'GS Construction'];
            }

            $ten = substr(preg_replace('/\D/', '', (string) $message->from_number) ?? '', -10);
            $user = $byPhone->get($ten);

            return [$message->id => $user
                ? $name($user)
                : ($ten ? phone_display($ten) : 'Unknown')];
        })->all();
    }

    /** Trailing "-PS"-style sign-off initials from a text, uppercased. */
    private static function signatureInitials(string $text): ?string
    {
        return preg_match('/-\s*([A-Za-z]{2,3})\s*$/', trim($text), $m)
            ? strtoupper($m[1])
            : null;
    }

    /** Blur-up placeholder for a texted photo — null when the file isn't local. */
    private function smsMicro(string $url): ?string
    {
        $path = app(ProjectImageImporter::class)->resolveSmsMediaPath($url);

        if (! $path || ! is_file($path)) {
            return null;
        }

        $key = $path.':'.filesize($path).':'.filemtime($path);

        return ImageThumbs::microDataUri(
            $key,
            fn () => ImageThumbs::thumbFileFor($key) ?? $path,
        );
    }

    /**
     * Times on frames/messages read in the vendor's local time (Chicago),
     * not the server's UTC.
     */
    private function vendorTimezone(Project $project): string
    {
        return \App\Models\Vendor::withoutGlobalScopes()
            ->find($project->belongs_to_vendor_id)?->timezone ?: 'America/Chicago';
    }

    /**
     * Conversations photos could go to, newest activity first — ported
     * verbatim from TimelapseStudio::textableThreads().
     */
    public function textableThreads(string $search = ''): Collection
    {
        $vendorId = auth()->user()->vendor?->id;

        if (! $vendorId) {
            return collect();
        }

        return SmsGroupThread::query()
            ->visibleToVendor($vendorId)
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.trim($search).'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)
                    ->orWhereHas('client', fn ($c) => $c->where('business_name', 'like', $term))
                    ->orWhereHas('project', fn ($p) => $p->where('address', 'like', $term)));
            })
            ->with(['client:id,business_name', 'project:id,address'])
            ->orderByDesc('last_activity_at')
            ->limit(100)
            ->get();
    }

    /** Same label TimelapseStudio::textThreadLabel() renders in the modal. */
    public function threadLabel(SmsGroupThread $thread): string
    {
        return $thread->name
            ?: ($thread->client?->name ?: $thread->client?->business_name)
            ?: $thread->project?->address
            ?: 'Conversation #'.$thread->id;
    }

    /**
     * Text selected project/message photos to a conversation — the actual
     * send, shared by TimelapseStudio::textImages() and the app's
     * ProjectMessageImageController::send().
     *
     * @param  array<int, int|string>  $frameIds
     * @param  array<int, string>  $messageUrls
     * @return array{ok: bool, reason: ?string, count: int}
     */
    public function sendTextImages(
        Project $project,
        SmsGroupThread $thread,
        array $frameIds,
        array $messageUrls,
        string $note,
        ?int $userId,
        GroupSmsService $sms,
    ): array {
        if ($thread->hasPendingOptIn()) {
            return ['ok' => false, 'reason' => 'pending_opt_in', 'count' => 0];
        }

        $frames = ProjectTimelapseFrame::query()
            ->whereIn('id', $frameIds)
            ->whereHas('timelapse', fn ($q) => $q->where('project_id', $project->id))
            ->get();

        // MMS attachments live in sms-attachments/ — the send pipeline
        // already knows how to sign and serve that path.
        $mediaUrls = $frames->map(function (ProjectTimelapseFrame $frame) {
            $source = Storage::disk($frame->disk)->path($frame->display_path);

            if (! is_file($source) && $frame->original_path) {
                $source = Storage::disk($frame->disk)->path($frame->original_path);
            }

            if (! is_file($source)) {
                return null;
            }

            $target = 'sms-attachments/'.Str::uuid().'.jpg';
            Storage::disk('files')->put($target, file_get_contents($source));

            return $target;
        })->filter()->values()->all();

        // Texted photos forward as-is: already a shape the send pipeline
        // signs/serves. Re-checked against the project's own Message Images
        // list, same as the web.
        $known = $this->allMessageImages($project)->pluck('raw')->all();
        $mediaUrls = array_merge(
            $mediaUrls,
            array_values(array_filter($messageUrls, fn ($raw) => in_array($raw, $known, true))),
        );

        if (empty($mediaUrls)) {
            return ['ok' => false, 'reason' => 'unavailable', 'count' => 0];
        }

        $text = trim($note) !== ''
            ? trim($note)
            : 'Photos from '.($project->short_address ?? $project->address);

        $sms->sendToThread($thread, $text, $mediaUrls, $userId);

        return ['ok' => true, 'reason' => null, 'count' => count($mediaUrls)];
    }
}
