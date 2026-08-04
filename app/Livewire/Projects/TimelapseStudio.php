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
    use AuthorizesRequests, WithFileUploads;

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

    /** Fallback for devices without camera access: a plain file upload. */
    public $file = null;

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

        if ($threadIds->isEmpty()) {
            return collect();
        }

        $messages = \App\Models\SmsMessage::query()
            ->withoutGlobalScopes()
            ->whereIn('thread_id', $threadIds)
            ->whereNotNull('media_urls')
            ->latest('created_at')
            ->limit(120)
            ->get(['id', 'direction', 'media_urls', 'created_at', 'from_number', 'sent_by_user_id']);

        // Resolve every sender in two queries rather than one per photo.
        $senders = $this->senderNames($messages);

        $images = $messages
            ->flatMap(function ($message) use ($senders) {
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
                        'sent_at' => $message->created_at,
                        'sender' => $sender,
                        'label' => $sender.' · '.$message->created_at->format('M j, Y'),
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

        return $messages->mapWithKeys(function ($message) use ($users, $byPhone, $name) {
            if ($message->direction !== 'inbound' && $message->sent_by_user_id) {
                $user = $users->get($message->sent_by_user_id);

                return [$message->id => $user ? $name($user) : 'GS Construction'];
            }

            $ten = substr(preg_replace('/\D/', '', (string) $message->from_number) ?? '', -10);
            $user = $byPhone->get($ten);

            return [$message->id => $user
                ? $name($user)
                : ($ten ? phone_display($ten) : 'Unknown')];
        })->all();
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

    public function lightboxFrames(ProjectTimelapse $collection): array
    {
        return $collection->frames->map(fn (ProjectTimelapseFrame $frame) => [
            'id' => $frame->id,
            'url' => route('projects.timelapse.frame', $frame),
            'original' => route('projects.timelapse.frame', ['frame' => $frame, 'original' => 1]),
            'label' => $frame->created_at->format('M j, Y').' · '
                .($frame->takenBy?->nickname ?? $frame->takenBy?->first_name ?? ''),
        ])->values()->all();
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
        $this->validate(['frame' => 'required|mimes:jpg,jpeg,png|max:20480']);
        $this->storeFrame($this->frame);
        $this->frame = null;
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

    protected function storeFrame($upload, ?string $originalName = null): void
    {
        $this->authorize('view', $this->project);

        // Read the capture time BEFORE touching the pixels: orientate/encode
        // strips EXIF, so this is the only moment the true "when was this
        // taken" exists. Falls back to the file's own timestamp, then now.
        $shotAt = $this->exifShotAt($upload->getRealPath());

        $timelapse = $this->collection ?? ProjectTimelapse::generalFor($this->project);

        $filename = Str::uuid().'.jpg';
        $directory = sprintf('timelapse/%d', $this->project->id);

        // 1. THE ARCHIVE COPY — the file exactly as the camera produced it,
        //    full resolution, byte-for-byte. Written first and never touched
        //    again: everything below is derived and can be regenerated, this
        //    cannot.
        $originalPath = sprintf('%s/original-%s', $directory, $filename);
        Storage::disk('files')->put($originalPath, file_get_contents($upload->getRealPath()));

        // 2. THE SEQUENCE COPY — oriented (phones lean on the EXIF tag; the
        //    gsc slider and any later video render do not) and capped, so
        //    playback stays uniform and light. This is what alignment reads
        //    and what the timelapse shows.
        $image = Image::make($upload->getRealPath())->orientate();

        if (max($image->width(), $image->height()) > self::MAX_EDGE) {
            $image->resize(self::MAX_EDGE, self::MAX_EDGE, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }

        $path = sprintf('%s/%s', $directory, $filename);
        Storage::disk('files')->put($path, (string) $image->encode('jpg', 88));

        $frame = ProjectTimelapseFrame::create([
            'project_timelapse_id' => $timelapse->id,
            'taken_by_user_id' => auth()->id(),
            'filename' => $filename,
            'original_filename' => $originalName,
            'path' => $path,
            'original_path' => $originalPath,
            'disk' => 'files',
            'shot_at' => $shotAt,
            'sort_order' => ((int) $timelapse->frames()->max('sort_order')) + 1,
        ]);

        // Sequences get registered onto their anchor in the background (the
        // onion skin got it close; alignment removes the residual wobble).
        // A photo album is not a sequence — nothing to align it to.
        if ($timelapse->isTimelapse()) {
            \App\Jobs\AlignTimelapseFrame::dispatch($frame->id)->afterCommit();
        }

        unset($this->collections, $this->collection, $this->frames, $this->latestFrame);
    }

    /**
     * EXIF DateTimeOriginal, else the file's own mtime, else now. Bad or
     * missing EXIF is normal (canvas captures carry none) — never fatal.
     */
    protected function exifShotAt(string $path): \Illuminate\Support\Carbon
    {
        if (function_exists('exif_read_data')) {
            try {
                $exif = @exif_read_data($path);
                $taken = $exif['DateTimeOriginal'] ?? $exif['DateTimeDigitized'] ?? null;

                if (is_string($taken) && $taken !== '' && ! str_starts_with($taken, '0000')) {
                    return \Illuminate\Support\Carbon::createFromFormat('Y:m:d H:i:s', $taken);
                }
            } catch (\Throwable) {
                // Unreadable EXIF is not a reason to lose the photo.
            }
        }

        $mtime = @filemtime($path);

        return $mtime ? \Illuminate\Support\Carbon::createFromTimestamp($mtime) : now();
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
