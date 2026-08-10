<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\ProjectTimelapse;
use App\Models\ProjectTimelapseFrame;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * A project's images: several named COLLECTIONS side by side — timelapses
 * (onion-skin capture, registration, playback) and plain photo albums.
 *
 * One camera serves them all: pick the target collection and shoot. The
 * onion skin and alignment guide follow that choice, so switching rooms
 * never means switching pages.
 *
 * Anyone who can view the project can shoot; capturing progress photos is
 * crew work. Deleting a frame is the taker or an Admin.
 */
class TimelapseStudio extends Component
{
    use AuthorizesRequests, WithFileUploads {
        _startUpload as baseStartUpload;
    }

    /**
     * The signed-URL handshake changes no state, so re-rendering this whole
     * page for it is pure cost — every captured frame pays it once before the
     * bytes even start moving.
     */
    public function _startUpload($name, $fileInfo, $isMultiple)
    {
        $this->skipRender();

        return $this->baseStartUpload($name, $fileInfo, $isMultiple);
    }

    public Project $project;

    /**
     * Which collection the camera and uploads write into. In the URL (?c=)
     * so a refresh — or a shared link — lands on the same one, but NOT a
     * route segment: a path would invite wire:navigate, which re-mounts the
     * page, kills the MediaStream and re-prompts for camera permission.
     */
    #[Url(as: 'c', except: null)]
    public ?int $collectionId = null;

    /** Show only photos from this sender on the Message Images card. */
    public ?string $messageSender = null;

    /** Camera capture arrives here as a JPEG blob via Livewire's JS upload. */
    public $frame = null;

    /**
     * Rides along with each camera capture (deferred $wire.set just before
     * the upload): the browser's GPS fix and the shutter's wall-clock moment.
     * Canvas JPEGs carry no EXIF, so this is the only source of either.
     */
    public ?array $captureMeta = null;

    /** Fallback for devices without camera access: a plain file upload. */
    public $file = null;

    /**
     * Slot the next upload BEFORE every existing frame — for replacing a
     * timelapse's opening shot rather than appending to the end.
     */
    public bool $uploadAsFirst = false;

    /**
     * New-collection form. Only timelapses are created here — every static
     * photo belongs in the project's one "Project Images" album, so there is
     * no type to choose.
     */
    public string $newTitle = '';

    public bool $showNewCollection = false;

    /** The longest edge every stored frame is capped to. */
    public const MAX_EDGE = 1920;

    public function mount(Project $project): void
    {
        $this->project = $project;
        $this->authorize('view', $this->project);

        // Every project has the catch-all album for loose photos. Anything
        // else (a room's timelapse) is created deliberately.
        $general = ProjectTimelapse::generalFor($this->project);
        unset($this->collections);

        // The project card's "Capture" deep-links straight into shooting:
        // land with the camera already open on the general album.
        if (request()->boolean('capture')) {
            $this->collectionId = $general->id;
        }

        // The camera stays CLOSED until a collection is chosen — arriving on
        // this page is usually about looking at photos, not shooting one, and
        // an unrequested camera means an unrequested permission prompt.
        if ($this->collectionId !== null && ! $this->collections->contains('id', $this->collectionId)) {
            $this->collectionId = null;
        }
    }

    /**
     * Every collection on this project, each with its frames — the page is a
     * list of these, and the camera writes into the selected one.
     *
     * @return \Illuminate\Support\Collection<int, ProjectTimelapse>
     */
    #[Computed]
    public function collections()
    {
        return ProjectTimelapse::query()
            ->where('project_id', $this->project->id)
            ->with(['frames' => fn ($q) => $q->with('takenBy:id,first_name,nickname')
                ->orderBy('sort_order')->orderBy('id')])
            // The catch-all album leads, then the sequences in their own order.
            ->orderByRaw("CASE WHEN title = 'Project Images' THEN 0 ELSE 1 END")
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** The collection the camera is open on, or null when it's closed. */
    #[Computed]
    public function collection(): ?ProjectTimelapse
    {
        return $this->collectionId === null
            ? null
            : $this->collections->firstWhere('id', $this->collectionId);
    }

    /** Put the camera away. */
    public function closeCamera(): void
    {
        $this->collectionId = null;
        unset($this->collection, $this->frames, $this->latestFrame);
    }

    /** Start another timelapse or photo album on this project. */
    public function createCollection(): void
    {
        $this->authorize('view', $this->project);

        $this->validate([
            'newTitle' => 'required|string|max:80',
        ], [], ['newTitle' => 'name']);

        if (ProjectTimelapse::where('project_id', $this->project->id)
            ->where('title', trim($this->newTitle))->exists()) {
            $this->addError('newTitle', 'This project already has a collection with that name.');

            return;
        }

        $collection = ProjectTimelapse::create([
            'project_id' => $this->project->id,
            'title' => trim($this->newTitle),
            'kind' => ProjectTimelapse::KIND_TIMELAPSE,
            // display_mode belongs to gs.construction's renderer, which only
            // knows 'accordion' and 'slider' and silently falls back to
            // 'slider' for anything else — a gallery pushed there would play
            // as a before/after sequence. Keep the value it understands and
            // let `kind` be the thing Hive reasons about.
            'display_mode' => 'slider',
            'sort_order' => ((int) ProjectTimelapse::where('project_id', $this->project->id)->max('sort_order')) + 1,
        ]);

        $this->newTitle = '';
        $this->showNewCollection = false;
        unset($this->collections, $this->collection, $this->frames, $this->latestFrame);
        $this->collectionId = $collection->id;
    }

    /**
     * Photos texted about this project — every image on a thread tied to the
     * project or to its client, newest first.
     *
     * These are NOT frames: they live in the message thread, are never
     * aligned, and can't be deleted from here. They appear alongside the
     * project's own photos because "show me the pictures of this job" should
     * mean all of them, not just the ones someone remembered to upload.
     *
     * @return \Illuminate\Support\Collection<int, array{url:string, label:string, sent_at:\Illuminate\Support\Carbon}>
     */
    #[Computed]
    public function messageImages()
    {
        $clientId = $this->project->client_id;

        $threadIds = \App\Models\SmsGroupThread::query()
            ->withoutGlobalScopes()
            ->where(fn ($q) => $q->where('project_id', $this->project->id)
                ->when($clientId, fn ($inner) => $inner->orWhere('client_id', $clientId)))
            ->pluck('id');

        // Messages pinned here by "Add to Project" — photos texted on some
        // OTHER thread (a crew chat, say) that belong to this job.
        $pinnedIds = \Illuminate\Support\Facades\DB::table('project_pinned_messages')
            ->where('project_id', $this->project->id)
            ->pluck('sms_message_id');

        if ($threadIds->isEmpty() && $pinnedIds->isEmpty()) {
            return collect();
        }

        $messages = \App\Models\SmsMessage::query()
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

        // Resolve every sender in two queries rather than one per photo.
        $senders = self::firstNames($this->senderNames($messages));
        $tz = $this->vendorTimezone();

        $images = $messages
            ->flatMap(function ($message) use ($senders, $tz) {
                $sender = $senders[$message->id] ?? ($message->direction === 'inbound' ? 'Client' : 'GS Construction');

                return collect($message->media_urls ?? [])
                    // Texts carry PDFs and videos too; this card is images.
                    ->filter(fn ($url) => is_string($url)
                        && preg_match('/\.(jpe?g|png|heic|webp|gif)$/i', $url) === 1)
                    // Stored values are inconsistent — "/storage/sms-media/…",
                    // bare "sms-media/…", absolute URLs — and the files have
                    // since moved to the private disk. The messages UI already
                    // resolves all those shapes to the authed streaming route;
                    // use that same resolver rather than guessing a prefix.
                    ->map(fn ($url) => [
                        'url' => \App\Support\Sms\ConversationPresenter::mediaUrl($url),
                        // What the grid tile loads — the lightbox still opens
                        // the full photo from 'url'.
                        'thumb' => \App\Support\Sms\ConversationPresenter::mediaUrl($url, thumb: true),
                        // Inlined blur-up placeholder behind the tile.
                        'micro' => $this->smsMicro($url),
                        // The stored value, verbatim — selection hands it back
                        // when texting these photos onward.
                        'raw' => $url,
                        'sent_at' => $message->created_at->copy()->timezone($tz),
                        'sender' => $sender,
                        'label' => $sender.' · '.$message->created_at->copy()->timezone($tz)->format('n/j/y'),
                    ]);
            })
            ->values();

        // A filter that matches nothing (its sender aged out of the
        // newest-120 window) would blank the whole card with no chip left
        // to clear it — drop the filter instead and show everything.
        if ($this->messageSender !== null) {
            $filtered = $images->where('sender', $this->messageSender)->values();

            if ($filtered->isNotEmpty()) {
                return $filtered;
            }

            $this->messageSender = null;
        }

        return $images;
    }

    /** Everyone who has sent a photo about this project, with counts. */
    #[Computed]
    public function messageSenders()
    {
        // Counted across ALL of them, so the chips don't shrink to whatever
        // is currently filtered.
        $active = $this->messageSender;
        $this->messageSender = null;
        unset($this->messageImages);

        $counts = $this->messageImages->groupBy('sender')->map->count()->sortDesc();

        $this->messageSender = $active;
        unset($this->messageImages);

        return $counts;
    }

    /** Click a sender to see only theirs; click again to clear. */
    public function filterMessageSender(?string $sender): void
    {
        $this->messageSender = $this->messageSender === $sender ? null : $sender;
        unset($this->messageImages);
    }

    /** Blur-up placeholder for a texted photo — null when the file isn't local. */
    protected function smsMicro(string $url): ?string
    {
        $path = app(\App\Services\ProjectImageImporter::class)->resolveSmsMediaPath($url);

        if (! $path || ! is_file($path)) {
            return null;
        }

        // Same key the sms media thumb route uses, so the micro can derive
        // from the already-built 480px thumb instead of the full photo.
        $key = $path.':'.filesize($path).':'.filemtime($path);

        return \App\Support\ImageThumbs::microDataUri(
            $key,
            fn () => \App\Support\ImageThumbs::thumbFileFor($key) ?? $path,
        );
    }

    /** Blur-up placeholder for a stored frame. */
    public function frameMicro(ProjectTimelapseFrame $frame): ?string
    {
        $disk = \Illuminate\Support\Facades\Storage::disk($frame->disk);
        $path = $frame->aligned_path ?: $frame->path;

        if (! $path || ! $disk->exists($path)) {
            return null;
        }

        // Same key the frame thumb route uses (see TimelapseController).
        $key = $frame->disk.':'.$path.':'.$disk->size($path);

        return \App\Support\ImageThumbs::microDataUri(
            $key,
            fn () => \App\Support\ImageThumbs::thumbFileFor($key) ?? $disk->get($path),
        );
    }

    /**
     * Lightbox payload for a collection: viewing URL, ORIGINAL URL (the
     * untouched shot, not the registered copy) and a caption per image.
     *
     * Built here, not in the view: a closure inside a @php block nested in a
     * conditional is exactly what Blaze's compiler mangles.
     *
     * @return array<int, array{id:int, url:string, original:string, label:string}>
     */
    /**
     * Who sent each message, keyed by message id.
     *
     * Outbound carries the crew member who typed it; inbound only has a
     * number, matched against contacts the same way the messages screen does
     * (raw digits, with and without the country code).
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\SmsMessage>  $messages
     * @return array<int, string>
     */
    protected function senderNames($messages): array
    {
        $userIds = $messages->pluck('sent_by_user_id')->filter()->unique();

        $users = $userIds->isEmpty()
            ? collect()
            : \App\Models\User::withoutGlobalScopes()
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
            : \App\Models\User::withoutGlobalScopes()
                ->where(function ($query) use ($digits) {
                    foreach ($digits as $ten) {
                        $query->orWhere('cell_phone', $ten)->orWhere('cell_phone', '1'.$ten);
                    }
                })
                ->get(['id', 'first_name', 'last_name', 'nickname', 'cell_phone'])
                ->keyBy(fn ($user) => substr(preg_replace('/\D/', '', (string) $user->cell_phone) ?? '', -10));

        $name = fn ($user) => trim(($user->nickname ?: $user->first_name).' '.($user->last_name ?? '')) ?: 'Unknown';

        // Outbound sent before the app stamped sent_by_user_id: the crew signs
        // texts "-PS" / "-GS", so trailing initials name the sender. Matched
        // against this vendor's team; "-GSC" (the company sign-off) matches
        // nobody and falls through to the company label.
        $sigs = $messages
            ->filter(fn ($m) => $m->direction !== 'inbound' && ! $m->sent_by_user_id)
            ->map(fn ($m) => self::signatureInitials((string) $m->text))
            ->filter()
            ->unique();

        $bySignature = $sigs->isEmpty()
            ? collect()
            : \App\Models\User::withoutGlobalScopes()
                ->join('user_vendor', 'user_vendor.user_id', '=', 'users.id')
                ->where('user_vendor.vendor_id', $this->project->belongs_to_vendor_id)
                ->get(['users.id', 'users.first_name', 'users.last_name', 'users.nickname'])
                ->unique('id')
                ->keyBy(fn ($u) => strtoupper(mb_substr((string) $u->first_name, 0, 1).mb_substr((string) $u->last_name, 0, 1)));

        return $messages->mapWithKeys(function ($message) use ($users, $byPhone, $bySignature, $name) {
            if ($message->direction !== 'inbound') {
                if ($message->sent_by_user_id) {
                    $user = $users->get($message->sent_by_user_id);

                    return [$message->id => $user ? $name($user) : 'GS Construction'];
                }

                // Never label our own outbound with the shared business
                // number — it reads as a mystery sender on every chip.
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

    /**
     * Trailing "-PS"-style sign-off initials from a text, uppercased —
     * null when the message doesn't end in one.
     */
    protected static function signatureInitials(string $text): ?string
    {
        return preg_match('/-\s*([A-Za-z]{2,3})\s*$/', trim($text), $m)
            ? strtoupper($m[1])
            : null;
    }

    /**
     * First names only — "Mark & Gail Brodson" reads as "Mark & Gail",
     * "Patryk Szady" as "Patryk" — EXCEPT when two different people would
     * collapse to the same first name; those keep their full names apart.
     * Phone numbers and single-word names pass through untouched.
     *
     * @param  array<int|string, string>  $names
     * @return array<int|string, string>
     */
    public static function firstNames(array $names): array
    {
        $shorts = [];

        foreach (array_unique(array_values($names)) as $full) {
            $tokens = preg_split('/\s+/', trim($full)) ?: [];
            $shorts[$full] = (count($tokens) > 1 && ! preg_match('/\d/', $full))
                ? implode(' ', array_slice($tokens, 0, -1))
                : $full;
        }

        $collisions = array_count_values($shorts);

        foreach ($shorts as $full => $short) {
            if ($collisions[$short] > 1) {
                $shorts[$full] = $full;
            }
        }

        return array_map(fn ($full) => $shorts[$full] ?? $full, $names);
    }

    /** Lightbox payload for the texted photos. */
    public function lightboxMessageImages(): array
    {
        return $this->messageImages->map(fn (array $image) => [
            'id' => 0,
            'url' => $image['url'],
            // A texted photo IS its own original — nothing was derived.
            'original' => $image['url'],
            'label' => $image['label'],
        ])->values()->all();
    }

    /**
     * Who took each frame in a collection, shortened to first names — the
     * tiles and the lightbox both caption from this, so they never disagree.
     *
     * @return array<int, string>
     */
    public function frameTakers(ProjectTimelapse $collection): array
    {
        return self::firstNames(
            $collection->frames->mapWithKeys(fn ($f) => [$f->id => (string) $f->taker_name])->all()
        );
    }

    /**
     * Tile captions for every frame on the page — "Patryk · 8/1", or just
     * the date when nobody is recorded, keyed [collection id][frame id].
     *
     * A computed rather than an @if or @php inside the tile loop: both of
     * those positions trip Blaze's compiler, and the view can read this with
     * a plain echo.
     *
     * @return array<int, array<int, string>>
     */
    /**
     * Times on frames read in the vendor's local time (Chicago), not the
     * server's UTC — "shot at 5:24 AM" for a midnight photo helps nobody.
     */
    public function vendorTimezone(): string
    {
        return \App\Models\Vendor::withoutGlobalScopes()
            ->find($this->project->belongs_to_vendor_id)?->timezone ?: 'America/Chicago';
    }

    #[Computed]
    public function frameCaptions(): array
    {
        $tz = $this->vendorTimezone();

        return $this->collections->mapWithKeys(function (ProjectTimelapse $collection) use ($tz) {
            $takers = $this->frameTakers($collection);

            $captions = $collection->frames->mapWithKeys(function (ProjectTimelapseFrame $frame) use ($takers, $tz) {
                $date = ($frame->shot_at ?? $frame->created_at)->copy()->timezone($tz)->format('n/j');
                $who = trim((string) ($takers[$frame->id] ?? ''));

                return [$frame->id => $who === '' ? $date : $who.' · '.$date];
            })->all();

            return [$collection->id => $captions];
        })->all();
    }

    public function lightboxFrames(ProjectTimelapse $collection): array
    {
        $takers = $this->frameTakers($collection);

        return $collection->frames->map(fn (ProjectTimelapseFrame $frame) => [
            'id' => $frame->id,
            'url' => route('projects.timelapse.frame', [$frame, 'v' => $frame->version]),
            'original' => route('projects.timelapse.frame', ['frame' => $frame, 'original' => 1]),
            'label' => ($frame->shot_at ?? $frame->created_at)->copy()->timezone($this->vendorTimezone())->format('n/j/y g:iA').' · '
                .($takers[$frame->id] ?? ''),
            // Where it was taken, when we know — camera GPS or upload EXIF.
            'map' => $frame->latitude !== null
                ? sprintf('https://maps.google.com/?q=%.7f,%.7f', $frame->latitude, $frame->longitude)
                : null,
        ])->values()->all();
    }

    /* ─── Text selected photos ────────────────────────────────────── */

    public bool $showTextImagesModal = false;

    public ?int $textTargetThreadId = null;

    public string $textThreadSearch = '';

    public string $textNote = '';

    /** @var array<int, int> */
    public array $textFrameIds = [];

    /** @var array<int, string> Stored media values of selected texted photos. */
    public array $textMessageUrls = [];

    public function openTextImagesModal(array $frameIds, array $messageUrls = []): void
    {
        $ids = collect($frameIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        // Only this project's frames — an id from anywhere else just drops.
        $this->textFrameIds = ProjectTimelapseFrame::query()
            ->whereIn('id', $ids)
            ->whereHas('timelapse', fn ($q) => $q->where('project_id', $this->project->id))
            ->pluck('id')
            ->all();

        // Texted photos: only values actually on this project's Message
        // Images card — anything else from the client just drops.
        $known = $this->messageImages->pluck('raw')->all();
        $this->textMessageUrls = collect($messageUrls)
            ->filter(fn ($raw) => is_string($raw) && in_array($raw, $known, true))
            ->unique()
            ->values()
            ->all();

        if (empty($this->textFrameIds) && empty($this->textMessageUrls)) {
            \Flux\Flux::toast(variant: 'warning', text: 'Select at least one photo to text.', duration: 4000, position: 'top right');

            return;
        }

        $this->textTargetThreadId = null;
        $this->textThreadSearch = '';
        $this->textNote = '';
        $this->showTextImagesModal = true;
        \Flux\Flux::modal('text-images')->show();
    }

    /** Conversations the photos could go to, newest activity first. */
    #[Computed]
    public function textableThreads(): \Illuminate\Support\Collection
    {
        $vendorId = auth()->user()->vendor?->id;

        if (! $vendorId) {
            return collect();
        }

        return \App\Models\SmsGroupThread::query()
            ->visibleToVendor($vendorId)
            ->when($this->textThreadSearch !== '', function ($q) {
                $term = '%'.trim($this->textThreadSearch).'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)
                    ->orWhereHas('client', fn ($c) => $c->where('business_name', 'like', $term))
                    ->orWhereHas('project', fn ($p) => $p->where('address', 'like', $term)));
            })
            ->with(['client:id,business_name', 'project:id,address'])
            ->orderByDesc('last_activity_at')
            ->limit(100)
            ->get();
    }

    public function textThreadLabel(\App\Models\SmsGroupThread $thread): string
    {
        return $thread->name
            ?: ($thread->client?->name ?: $thread->client?->business_name)
            ?: $thread->project?->address
            ?: 'Conversation #'.$thread->id;
    }

    public function textImages(\App\Services\GroupSmsService $sms): void
    {
        $this->authorize('view', $this->project);

        $this->validate([
            'textTargetThreadId' => 'required|integer',
            'textFrameIds' => 'array',
            'textMessageUrls' => 'array',
            'textNote' => 'nullable|string|max:1200',
        ], [], ['textTargetThreadId' => 'conversation']);

        if (empty($this->textFrameIds) && empty($this->textMessageUrls)) {
            $this->addError('textFrameIds', 'Select at least one photo.');

            return;
        }

        $thread = $this->textableThreads->firstWhere('id', (int) $this->textTargetThreadId);

        if (! $thread) {
            $this->addError('textTargetThreadId', 'Pick a conversation.');

            return;
        }

        if ($thread->hasPendingOptIn()) {
            \Flux\Flux::toast(variant: 'warning', heading: 'Awaiting START Replies', text: 'That conversation has participants who have not replied START yet.', duration: 5000, position: 'top right');

            return;
        }

        $frames = ProjectTimelapseFrame::query()
            ->whereIn('id', $this->textFrameIds)
            ->whereHas('timelapse', fn ($q) => $q->where('project_id', $this->project->id))
            ->get();

        // MMS attachments live in sms-attachments/ — the send pipeline
        // already knows how to sign and serve that path, so give it copies
        // there rather than teaching it about timelapse storage.
        $mediaUrls = $frames->map(function (ProjectTimelapseFrame $frame) {
            $source = Storage::disk($frame->disk)->path($frame->display_path);

            // A just-shot frame's sequence copy may still be in the queue —
            // the archive copy stands in rather than silently dropping the
            // photo from the text.
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

        // Texted photos forward as-is: their stored sms-media/… values (or
        // external URLs) are already shapes the send pipeline signs/serves —
        // no copy needed. Re-checked against the card, same as on open.
        $known = $this->messageImages->pluck('raw')->all();
        $mediaUrls = array_merge(
            $mediaUrls,
            array_values(array_filter($this->textMessageUrls, fn ($raw) => in_array($raw, $known, true))),
        );

        if (empty($mediaUrls)) {
            $this->addError('textFrameIds', 'Those photos are not available on this server.');

            return;
        }

        $text = trim($this->textNote) !== ''
            ? trim($this->textNote)
            : 'Photos from '.($this->project->short_address ?? $this->project->address);

        $sms->sendToThread($thread, $text, $mediaUrls, auth()->id());

        $this->showTextImagesModal = false;
        $this->textFrameIds = [];
        $this->textMessageUrls = [];
        \Flux\Flux::modal('text-images')->close();
        $this->dispatch('text-images-sent');

        \Flux\Flux::toast(
            variant: 'success',
            heading: 'Sent',
            text: count($mediaUrls).' '.\Illuminate\Support\Str::plural('photo', count($mediaUrls)).' texted to '.$this->textThreadLabel($thread).'.',
            duration: 4000,
            position: 'top right'
        );
    }

    /** Switching target reloads the onion skin and the alignment guide. */
    public function selectCollection(int $collectionId): void
    {
        if ($this->collections->contains('id', $collectionId)) {
            $this->collectionId = $collectionId;
            unset($this->collection, $this->frames, $this->latestFrame);
        }
    }

    public function deleteCollection(int $collectionId): void
    {
        abort_unless(auth()->user()->vendor_role === 'Admin', 403);

        $collection = ProjectTimelapse::query()
            ->where('project_id', $this->project->id)
            ->findOrFail($collectionId);

        // The catch-all album is where an unaimed upload lands — removing it
        // would leave those photos nowhere to go.
        abort_if($collection->title === 'Project Images', 403);

        // Frames (and their files) go with it.
        foreach ($collection->frames as $frame) {
            $frame->delete();
        }

        $collection->delete();

        unset($this->collections, $this->collection, $this->frames, $this->latestFrame);
        $this->collectionId = $this->collections->first()?->id;
    }

    /**
     * Frames in shooting order.
     *
     * @return \Illuminate\Support\Collection<int, ProjectTimelapseFrame>
     */
    #[Computed]
    public function frames()
    {
        return $this->collection?->frames ?? collect();
    }

    #[Computed]
    public function latestFrame(): ?ProjectTimelapseFrame
    {
        return $this->frames->last();
    }

    /** A camera capture (JPEG blob) landed — store it as the next frame. */
    public function updatedFrame(): void
    {
        $t0 = microtime(true);
        $bytes = (int) $this->frame?->getSize();

        $this->validate(['frame' => 'required|mimes:jpg,jpeg,png|max:20480']);

        // The camera stamps the shot's collection into the filename
        // ("frame-{id}.jpg"): uploads are queued client-side and the finish
        // request can land AFTER the user switched collections — resolving
        // "current collection" then would misfile the frame. The lookup is
        // against this project's own collections, so a forged id gets nothing.
        preg_match('/^frame-(\d+)\.jpg$/', (string) $this->frame->getClientOriginalName(), $m);
        $target = $m ? $this->collections->firstWhere('id', (int) $m[1]) : null;

        $meta = $this->sanitizedCaptureMeta();
        $this->storeFrame($this->frame, null, $meta, $target);
        $this->frame = null;
        $this->captureMeta = null;

        // Two numbers tell the whole story when "Saving…" drags:
        //   store_ms   — writing the archive + the row (should be small now
        //                that the resize is queued);
        //   request_ms — the WHOLE finish commit, logged at terminate so it
        //                includes re-rendering this (large) component. A big
        //                request_ms with a small store_ms means the render is
        //                the problem; small both means the time is on the
        //                wire or in the client.
        $storeMs = (int) round((microtime(true) - $t0) * 1000);
        $shutterAt = $meta['takenAt'] ?? null;

        app()->terminating(function () use ($bytes, $storeMs, $shutterAt) {
            \Illuminate\Support\Facades\Log::channel('timelapse')->info('Frame upload commit', [
                'project_id' => $this->project->id,
                'bytes' => $bytes,
                'store_ms' => $storeMs,
                'request_ms' => defined('LARAVEL_START')
                    ? (int) round((microtime(true) - LARAVEL_START) * 1000)
                    : null,
                // Shutter-press → this commit finishing, in seconds: the
                // number the crew member actually experiences.
                'shutter_to_saved_s' => $shutterAt
                    ? max(0, round(now()->diffInMilliseconds(\Illuminate\Support\Carbon::parse($shutterAt), true) / 1000, 1))
                    : null,
            ]);
        });
    }

    /**
     * Client-supplied metadata is still client-supplied: coordinates outside
     * the globe or a "taken at" from a wildly wrong phone clock are dropped,
     * never stored.
     *
     * @return array{lat: ?float, lng: ?float, accuracy: ?int, takenAt: ?\Illuminate\Support\Carbon}|null
     */
    protected function sanitizedCaptureMeta(): ?array
    {
        return self::sanitizeCaptureMeta(is_array($this->captureMeta) ? $this->captureMeta : null);
    }

    /**
     * Static so the direct-upload endpoint (TimelapseController::store) can
     * apply exactly the same rules to the same fields.
     *
     * @return array{lat: ?float, lng: ?float, accuracy: ?int, takenAt: ?\Illuminate\Support\Carbon}|null
     */
    public static function sanitizeCaptureMeta(?array $meta): ?array
    {
        if (! is_array($meta)) {
            return null;
        }

        $lat = is_numeric($meta['lat'] ?? null) ? (float) $meta['lat'] : null;
        $lng = is_numeric($meta['lng'] ?? null) ? (float) $meta['lng'] : null;

        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180 || ($lat === 0.0 && $lng === 0.0)) {
            $lat = $lng = null;
        }

        $accuracy = is_numeric($meta['accuracy'] ?? null)
            ? (int) min(max((float) $meta['accuracy'], 0), 1000000)
            : null;

        $takenAt = null;

        if (is_string($meta['takenAt'] ?? null)) {
            try {
                $parsed = \Illuminate\Support\Carbon::parse($meta['takenAt']);

                // The shutter fired seconds before this request — anything
                // outside a generous window is a broken phone clock.
                if ($parsed->between(now()->subDay(), now()->addMinutes(5))) {
                    $takenAt = $parsed;
                }
            } catch (\Throwable) {
                // Unparseable timestamp — the upload time will do.
            }
        }

        return ($lat === null && $takenAt === null) ? null : compact('lat', 'lng', 'accuracy', 'takenAt');
    }

    /** Plain file-upload fallback (camera denied / desktop). */
    public function uploadFile(): void
    {
        $this->validate(['file' => 'required|mimes:jpg,jpeg,png|max:20480'], [
            'file.mimes' => 'Frames must be JPG or PNG images.',
        ]);
        $this->storeFrame($this->file, $this->file->getClientOriginalName());
        $this->file = null;
    }

    protected function storeFrame($upload, ?string $originalName = null, ?array $meta = null, ?ProjectTimelapse $target = null): void
    {
        $this->authorize('view', $this->project);

        $timelapse = $target ?? $this->collection ?? ProjectTimelapse::generalFor($this->project);

        // "Make first": everything currently in the sequence comes after it.
        $sortOrder = null;
        if ($this->uploadAsFirst) {
            $sortOrder = ((int) $timelapse->frames()->min('sort_order')) - 1;
            $this->uploadAsFirst = false;
        }

        app(\App\Services\ProjectImageImporter::class)->storeImage(
            $timelapse,
            $upload->getRealPath(),
            $originalName,
            null,
            // The camera's live fix beats EXIF (canvas JPEGs have none).
            ($meta['lat'] ?? null) !== null
                ? ['lat' => $meta['lat'], 'lng' => $meta['lng'], 'accuracy' => $meta['accuracy'] ?? null]
                : null,
            $meta['takenAt'] ?? null,
            auth()->id(),
            null,
            // The crew is standing there watching "Saving…" — write the bytes,
            // answer, and let the queue do the pixel work.
            deferProcessing: true,
            sortOrder: $sortOrder,
        );

        unset($this->collections, $this->collection, $this->frames, $this->latestFrame);
    }

    /** The taker can remove their own bad frame; Admins can remove any. */
    public function deleteFrame(int $frameId): void
    {
        $frame = ProjectTimelapseFrame::query()
            ->whereHas('timelapse', fn ($q) => $q->where('project_id', $this->project->id))
            ->findOrFail($frameId);

        $isAdmin = auth()->user()->vendor_role === 'Admin';

        abort_unless($isAdmin || $frame->taken_by_user_id === auth()->id(), 403);

        $frame->delete();

        unset($this->collections, $this->collection, $this->frames, $this->latestFrame);
    }

    #[Title('Images')]
    public function render()
    {
        return view('livewire.projects.timelapse-studio');
    }
}
