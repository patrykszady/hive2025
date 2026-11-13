<?php

namespace App\Livewire\Estimates;

use App\Exceptions\Estimates\SectionNotOnEstimate;
use App\Models\Estimate;
use App\Models\EstimateAiRule;
use App\Models\EstimateAiRun;
use App\Models\EstimateLineItem;
use App\Services\Estimates\EstimateAiDraftWriter;
use App\Services\Estimates\EstimateAiRuleWriter;
use App\Services\Estimates\FloorplanParser;
use App\Services\EstimateAI\EstimateDraftPlanner;
use App\Services\EstimateAI\EstimateDraftRuns;
use App\Services\EstimateAIService;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The AI estimate generator: drafts a whole estimate, section by section.
 *
 * The estimator describes the job and lists the sections to draft. A room
 * scan fills that list by itself — every room it measured is offered as a
 * section the moment the file is uploaded — and each section is then drafted
 * from its own room's measurements. With nothing listed, Claude splits the
 * work into sections itself.
 *
 * The drafting runs in a queued job (App\Jobs\DraftEstimateSections, one
 * EstimateAiRun): a whole estimate takes longer than a web request may. This
 * window follows the run, showing each line as it lands on the estimate.
 */
class EstimateAIGenerator extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public Estimate $estimate;

    public string $inquiry = '';

    /**
     * The layout files for this draft, one per floor (or basement,
     * 2026-09-30): each is measured and the numbers are added up, with each
     * floor's own kept beside the totals.
     *
     * @var list<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile>
     */
    public array $floorplans = [];

    /** @var list<string> A name per layout, same index as $floorplans ("Floor 1", "Basement"). */
    public array $floorplanLabels = [];

    /** The file picker: whatever it receives joins $floorplans, so a second floor can be added after the first. */
    public $newFloorplans = [];

    /**
     * The sections this draft may fill. A "room" row came from an uploaded
     * scan and carries that room's measurements (looked up again from the
     * file when the draft starts — never trusted from here); a "saved" row is
     * a section of an earlier request used again, measurements and all; a
     * "manual" row was typed in; an "existing" row is a section already on
     * the estimate.
     * A row merged into another (merged_into holds that row's key) is drafted
     * as part of it — its room's measurements added to the other's — and so
     * are ticked rows that share a name.
     *
     * @var list<array{key: string, kind: string, name: string, include: bool, section_id: ?int, detail: string, sqft: ?float, merged_into: ?string}>
     */
    public array $sectionRows = [];

    /** @var list<array<string, mixed>> The drafted lines, in drafted order, each with its section. */
    public array $generatedItems = [];

    public string $reasoning = '';

    public bool $isGenerating = false;

    public bool $showPreview = false;

    public string $error = '';

    /** The run this window is following or reviewing. Never client-set. */
    #[Locked]
    public ?int $runId = null;

    /**
     * An earlier request of this estimate being used again: its sections and
     * their room measurements stand in the list as "saved" rows, read back
     * from that run when the draft starts. Never client-set.
     */
    #[Locked]
    public ?int $reusedRunId = null;

    public bool $showRules = false;

    public string $newRule = '';

    /** The section of the review being drafted again, and what the estimator wants different about it. */
    public ?int $redoSectionId = null;

    public string $redoNote = '';

    /**
     * Whether the window is up. Closed while a draft is being written, the
     * draft carries on and is checked on quietly; it is not thrown back over
     * whatever the estimator went on to do. Server-kept: a client can only
     * say "closed" (windowClosed()).
     */
    #[Locked]
    public bool $windowOpen = false;

    protected function rules(): array
    {
        return [
            'inquiry' => 'required|min:10',
            'floorplans.*' => 'file|mimes:pdf,jpg,jpeg,png,csv|max:10240',
            'sectionRows.*.name' => 'nullable|string|max:60',
        ];
    }

    public function mount(): void
    {
        $this->sectionRows = $this->existingSectionRows();
        // What this person had typed for this estimate and not yet sent — the page may have been reloaded since.
        $this->inquiry = (string) Cache::get($this->unsentKey(), '');

        // The page was reloaded (or reopened) while this person's own draft is
        // still being written: the window comes back up on it by itself.
        $running = $this->runningDraft();
        if ($running !== null && auth()->id() !== null && (int) $running->user_id === (int) auth()->id()) {
            $this->runId = $running->id;
            $this->isGenerating = true;
            $this->windowOpen = true;
        }
    }

    /**
     * The description is kept as it is typed (the box sends it when left), so
     * a reload, or a browser that closes, does not cost a long paragraph.
     * Kept per estimate and per person, until it is sent or Done clears it.
     */
    public function updatedInquiry(): void
    {
        trim($this->inquiry) === ''
            ? Cache::forget($this->unsentKey())
            : Cache::put($this->unsentKey(), $this->inquiry, now()->addDays(14));
    }

    protected function unsentKey(): string
    {
        return 'estimate-ai-unsent:'.$this->estimate->id.':'.(auth()->id() ?? 0);
    }

    /** The window was closed (its X, Escape): nothing is lost, and nothing is forced back open. */
    public function windowClosed(): void
    {
        $this->windowOpen = false;
    }

    /** Files picked in the upload box join the list, each named "Floor N" until renamed, and their rooms join the sections. */
    public function updatedNewFloorplans(): void
    {
        $this->validate(['newFloorplans.*' => 'file|mimes:pdf,jpg,jpeg,png,csv|max:10240']);

        foreach ((array) $this->newFloorplans as $file) {
            $this->floorplans[] = $file;
            $this->floorplanLabels[] = 'Floor '.count($this->floorplans);
        }

        $this->newFloorplans = [];
        $this->syncRoomRows();
    }

    public function removeFloorplan(int $index): void
    {
        unset($this->floorplans[$index], $this->floorplanLabels[$index]);
        $this->floorplans = array_values($this->floorplans);
        $this->floorplanLabels = array_values($this->floorplanLabels);
        $this->syncRoomRows();
    }

    public function updatedFloorplanLabels(): void
    {
        $this->syncRoomRows();
    }

    public function addSectionRow(): void
    {
        $this->sectionRows[] = ['key' => 'manual-'.Str::lower(Str::random(8)), 'kind' => 'manual', 'name' => '', 'include' => true, 'section_id' => null, 'detail' => '', 'sqft' => null, 'merged_into' => null, 'note' => ''];
    }

    public function removeSectionRow(string $key): void
    {
        $this->sectionRows = $this->withoutLooseMerges(array_values(array_filter(
            $this->sectionRows,
            fn (array $row) => ! (($row['key'] ?? null) === $key && in_array($row['kind'] ?? null, ['manual', 'saved'], true)),
        )));

        // A saved section taken out no longer speaks for its rooms: an uploaded scan lists them again.
        if (str_starts_with($key, 'saved-')) {
            $this->syncRoomRows();
        }
    }

    /** Clear the form — description, scans, sections, the earlier request in use — to begin a request from nothing. */
    public function startOver(): void
    {
        $this->reset(['inquiry', 'floorplans', 'floorplanLabels', 'newFloorplans', 'reusedRunId', 'error']);
        $this->resetErrorBag();
        Cache::forget($this->unsentKey());
        $this->sectionRows = $this->existingSectionRows();
    }

    /**
     * Put one room (or typed section) into another section: it is drafted as
     * part of that one, under that one's name, its measurements added in.
     * Whatever had been merged into it moves along, and the section it joins
     * is ticked. A section already on the estimate can take rooms in, but is
     * never itself merged away.
     */
    public function mergeSectionRow(string $key, string $intoKey): void
    {
        $rows = collect($this->sectionRows)->keyBy('key');
        $row = $rows->get($key);
        $into = $rows->get($intoKey);

        if ($row === null || $into === null || $key === $intoKey || ($row['kind'] ?? null) === 'existing' || ! empty($into['merged_into'])) {
            return;
        }

        foreach ($this->sectionRows as $index => $candidate) {
            if ($candidate['key'] === $key || ($candidate['merged_into'] ?? null) === $key) {
                $this->sectionRows[$index]['merged_into'] = $intoKey;
            } elseif ($candidate['key'] === $intoKey) {
                $this->sectionRows[$index]['include'] = true;
            }
        }
    }

    /** Take a merged room back out: it is its own section again, ticked. */
    public function splitSectionRow(string $key): void
    {
        foreach ($this->sectionRows as $index => $row) {
            if ($row['key'] === $key && ! empty($row['merged_into'])) {
                $this->sectionRows[$index]['merged_into'] = null;
                $this->sectionRows[$index]['include'] = true;
            }
        }
    }

    /**
     * A row merged into one that is no longer listed stands on its own again.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function withoutLooseMerges(array $rows): array
    {
        $heads = collect($rows)->filter(fn (array $row) => empty($row['merged_into']))->pluck('key')->all();

        return array_map(
            fn (array $row) => ! empty($row['merged_into']) && ! in_array($row['merged_into'], $heads, true) ? ['merged_into' => null] + $row : $row,
            $rows,
        );
    }

    #[On('openAIGenerator')]
    public function openModal(?int $sectionId = null): void
    {
        // What was typed and set up stays: a description is not lost because
        // the window was closed before it was sent (2026-11-13). Only a
        // finished draft ("Done") clears the form.
        $this->reset(['newFloorplans', 'generatedItems', 'reasoning', 'error', 'showPreview', 'runId', 'isGenerating', 'showRules', 'newRule']);
        $this->sectionRows = $this->refreshedSectionRows($sectionId);
        $this->windowOpen = true;

        // A draft still running for this estimate (the page was reloaded, or
        // a colleague started it): follow that one rather than start a second.
        $running = $this->runningDraft();
        if ($running !== null) {
            $this->runId = $running->id;
            $this->isGenerating = true;
        } elseif (($unreviewed = $this->unreviewedDraft()) !== null) {
            // A draft that finished and was never closed with Done or Discard
            // (the window was shut on its review): back to that review, not to
            // a form that would draft the same lines a second time.
            $this->runId = $unreviewed->id;
            $this->pollDraft();
        }

        $this->modal('estimate-ai-generator-modal')->show();
    }

    /**
     * The newest draft of this estimate that ended within the last day and
     * still waits for its Done or Discard — its lines on the estimate, its
     * sections' drafts not yet kept.
     */
    protected function unreviewedDraft(): ?EstimateAiRun
    {
        return $this->runs()->unreviewed($this->estimate);
    }

    public function generate(): void
    {
        $this->validate([
            'inquiry' => 'required|min:10',
            'sectionRows.*.name' => 'nullable|string|max:60',
            'sectionRows.*.note' => 'nullable|string|max:300',
        ]);

        $this->authorize('update', $this->estimate);

        $this->error = '';
        $this->generatedItems = [];
        $this->reasoning = '';
        $this->showPreview = false;

        $plan = $this->sectionPlan();

        if ($plan === null) {
            return;
        }

        // Room scans are read here and now; pictures and PDFs of plans are
        // put aside and read by the job (PlanPictures), so the click answers
        // at once. With no layout uploaded, the measurements saved with the
        // request being used again stand. One draft at a time per estimate:
        // if one is already running, that one is followed instead.
        [$run] = $this->runs()->start(
            $this->estimate,
            auth()->id(),
            $this->inquiry,
            $plan,
            $this->floorplans,
            $this->floorplanLabels,
            $this->reusedRun()?->floorplan,
        );

        // Sent: from here it is a saved request of this estimate, not unsent text.
        Cache::forget($this->unsentKey());

        $this->runId = $run->id;
        $this->isGenerating = true;
        $this->windowOpen = true;

        // Where jobs run in line (tests, a sync queue) the draft is finished already.
        $this->pollDraft();
    }

    /**
     * The window's heartbeat while a draft runs: the lines drafted so far,
     * and — once the run has ended, however it ended — the review of what is
     * on the estimate.
     */
    public function pollDraft(): void
    {
        $run = $this->run();

        if ($run === null) {
            $this->isGenerating = false;

            return;
        }

        if ($run->hasGoneSilent()) {
            // Its worker is gone (a deploy, a crash): nothing more will come.
            $this->writeOff($run);
        }

        $this->generatedItems = $this->rowsFor($run);
        $this->isGenerating = $run->isRunning();

        if ($this->isGenerating) {
            return;
        }

        $this->reasoning = (string) $run->reasoning;
        $this->error = match (true) {
            $run->status === EstimateAiRun::FAILED => $run->error ?: 'Failed to generate estimate',
            $run->status === EstimateAiRun::STOPPED => 'You stopped the draft.',
            $this->generatedItems === [] => 'Nothing was drafted. Describe the work in more detail and try again.',
            default => '',
        };

        // Whatever was drafted is on the estimate already, cut off or not.
        $this->showPreview = $this->generatedItems !== [];

        if (! $this->showPreview) {
            $this->runId = null;
        }

        $this->refreshEstimate();

        if ($this->windowOpen) {
            // Stay here to review the draft, whatever else re-rendered meanwhile.
            $this->modal('estimate-ai-generator-modal')->show();

            return;
        }

        // The window was closed while the draft was written: say it is ready
        // rather than put the review over what the estimator is doing now.
        Flux::toast(
            duration: 10000,
            position: 'top right',
            variant: $this->showPreview ? 'success' : 'warning',
            heading: $this->showPreview ? 'The estimate draft is ready' : 'The estimate draft did not finish',
            text: $this->showPreview
                ? count($this->generatedItems).' line items are on the estimate. Open AI Generate to review them.'
                : ($this->error ?: 'Open AI Generate to try again.'),
        );
    }

    /** Ask the running draft to stop; what it has drafted so far stays for review. */
    public function stopDraft(): void
    {
        $this->authorize('update', $this->estimate);

        $this->runs()->stop($this->estimate, $this->runId);
    }

    /**
     * What the draft is asked to fill, from the ticked rows
     * (EstimateDraftPlanner::plan, shared with the Hive Contractors app).
     *
     * Null — with a message — when a ticked row names a section that is not
     * (or is no longer) one of this estimate's: nothing is drafted then.
     *
     * @return array{listed: bool, sections: list<array{key: string, name: string, section_id: ?int, on_estimate: bool, rooms: list<array<string, mixed>>}>, left_out: list<array{name: string, rooms: list<array<string, mixed>>}>}|null
     */
    protected function sectionPlan(): ?array
    {
        try {
            return $this->planner()->plan($this->estimate, $this->sectionRows, $this->scannedRooms());
        } catch (SectionNotOnEstimate $stale) {
            $this->sectionRows = $this->refreshedSectionRows();
            $this->error = $stale->getMessage();

            return null;
        }
    }

    /**
     * The measured rooms behind each row, by row key: a scanned room read
     * from its file, and the rooms saved with a section of the earlier
     * request being used again — each room's numbers reduced to what sizes
     * an estimate. Never taken from the rows themselves.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function scannedRooms(): array
    {
        $parser = app(FloorplanParser::class);
        $rooms = [];

        foreach ($this->floorplans as $file) {
            foreach ($parser->rooms($file) as $room) {
                $rooms[$this->roomKey($file, (string) $room['name'])] = [EstimateAIService::roomMetrics($room)];
            }
        }

        $reused = $this->reusedRun();

        return $reused !== null ? $rooms + $this->planner()->savedRooms($reused, $this->roomsInScans()) : $rooms;
    }

    /**
     * What an earlier request saved, by the row key each comes back under.
     *
     * @return array<string, array{name: string, rooms: list<array<string, mixed>>, listed: bool, note: string}>
     */
    protected function savedSections(EstimateAiRun $run): array
    {
        return $this->planner()->savedSections($run);
    }

    /** The earlier request being used again — only ever one of this estimate's own. */
    protected function reusedRun(): ?EstimateAiRun
    {
        return $this->runs()->find($this->estimate, $this->reusedRunId);
    }

    protected function savedKey(EstimateAiRun $run, string $sectionKey): string
    {
        return $this->planner()->savedKey($run, $sectionKey);
    }

    /**
     * Use an earlier request of this estimate again: its description back in
     * the box, and the sections it listed — names, merged rooms and their
     * measurements — back in the list, so nothing is typed, uploaded or
     * merged a second time. Sections typed in or scanned since stay.
     */
    public function useRequest(int $runId): void
    {
        $this->authorize('update', $this->estimate);

        $run = EstimateAiRun::query()->where('estimate_id', $this->estimate->id)->find($runId);
        if ($run === null) {
            return;
        }

        $this->inquiry = (string) $run->inquiry;
        $this->updatedInquiry();
        $this->reusedRunId = $run->id;
        $this->error = '';
        $this->resetErrorBag();

        $saved = [];
        foreach ($this->savedSections($run) as $key => $section) {
            $saved[] = $this->savedRow($key, $section);
        }

        $kept = array_values(array_filter($this->sectionRows, fn (array $row) => ($row['kind'] ?? null) !== 'saved'));
        $this->sectionRows = $this->withoutLooseMerges([...$saved, ...$kept]);

        // A scan already uploaded lists only the rooms the saved sections do not already hold.
        $this->syncRoomRows();
    }

    /**
     * One saved section as a row, at the newest measurement of its rooms:
     * the uploaded scan's, where it has a room of the same name.
     *
     * @param  array{name: string, rooms: list<array<string, mixed>>, listed: bool, note: string}  $section
     * @param  array<string, mixed>|null  $kept  the row as the estimator left it (name, tick, merge, note)
     * @return array<string, mixed>
     */
    protected function savedRow(string $key, array $section, ?array $kept = null): array
    {
        return $this->planner()->savedRow($key, $section, $kept, $this->roomsInScans());
    }


    /**
     * Every room the uploaded scans measure, by its name as scanned (lower-cased).
     *
     * @return array<string, array<string, mixed>>
     */
    protected function roomsInScans(): array
    {
        $parser = app(FloorplanParser::class);
        $rooms = [];

        foreach ($this->floorplans as $file) {
            foreach ($parser->rooms($file) as $room) {
                $rooms[Str::lower(trim((string) $room['name']))] ??= $room;
            }
        }

        return $rooms;
    }

    /**
     * The scanned-room names the saved sections now in the list already
     * hold: a scan uploaded beside them does not list those rooms a second
     * time (2026-11-13: "Use again", then the same scan uploaded, doubled
     * every section — and would have drafted each room at twice its size).
     *
     * @return list<string>
     */
    protected function roomsHeldBySavedRows(): array
    {
        $run = $this->reusedRun();

        if ($run === null) {
            return [];
        }

        $present = collect($this->sectionRows)->where('kind', 'saved')->pluck('key')->all();

        return collect($this->savedSections($run))
            ->only($present)
            ->flatMap(fn (array $section) => array_column(array_filter($section['rooms'], 'is_array'), 'name'))
            ->map(fn ($name) => Str::lower(trim((string) $name)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Take an earlier draft off the estimate — its lines, and the sections it
     * brought in — so the request can be drafted again without doubling up.
     * The same as Discard Draft, for a draft already closed with Done.
     */
    public function removeDraft(int $runId): void
    {
        $this->authorize('update', $this->estimate);

        $run = EstimateAiRun::query()->where('estimate_id', $this->estimate->id)->find($runId);
        if ($run === null || $run->isRunning()) {
            return;
        }

        $writer = app(EstimateAiDraftWriter::class);
        $signed = $writer->standing($this->estimate, $run->load('drafts'))['signed'];
        $result = $writer->discardRun($this->estimate, $run);

        $this->sectionRows = $this->refreshedSectionRows();
        $this->refreshEstimate();

        Flux::toast(
            duration: 8000,
            position: 'top right',
            heading: 'Draft removed',
            text: 'Its line items, and the sections it added, are off the estimate.'
                .($signed > 0 ? ' '.$signed.' '.Str::plural('line', $signed).' in the signed contract stayed.' : '')
                .($result['kept_combined'] > 0 ? ' '.$result['kept_combined'].' combined '.Str::plural('line', $result['kept_combined']).' also '.($result['kept_combined'] === 1 ? 'carries' : 'carry').' other work and stayed: check '.($result['kept_combined'] === 1 ? 'it' : 'them').'.' : ''),
        );
    }

    /**
     * The requests typed for this estimate before, newest first, each once.
     *
     * @return list<array{id: int, inquiry: string, when: string, by: ?string, outcome: string, on_estimate: int, sections: int}>
     */
    protected function earlierRequests(): array
    {
        return $this->planner()->earlierRequests($this->estimate);
    }

    /**
     * The rooms of the uploaded scans as section rows: every new room is
     * added ticked, rooms of a removed scan go, and a row the estimator
     * renamed, unticked, merged or split keeps that. A sliver the scan left
     * beside a room ("Living Room (3)", 15' 9" x 0' 11") starts out merged
     * into the room of the same name, where its few square feet belong —
     * the estimator can split it back out.
     */
    protected function syncRoomRows(): void
    {
        $parser = app(FloorplanParser::class);
        $previous = collect($this->sectionRows);
        $severalLayouts = count($this->floorplans) > 1;
        $held = $this->roomsHeldBySavedRows();
        $roomRows = [];

        foreach ($this->floorplans as $index => $file) {
            $label = trim((string) ($this->floorplanLabels[$index] ?? '')) ?: 'Floor '.($index + 1);

            $scanned = $parser->rooms($file);

            foreach ($scanned as $room) {
                // Already in a saved section of the request being used again: not listed twice.
                if (in_array(Str::lower(trim((string) $room['name'])), $held, true)) {
                    continue;
                }

                $key = $this->roomKey($file, (string) $room['name']);
                $kept = $previous->firstWhere('key', $key);
                $taken = collect($roomRows)->contains(fn (array $row) => Str::lower($row['name']) === Str::lower((string) $room['name']));

                $roomRows[] = [
                    'key' => $key,
                    'kind' => 'room',
                    // Two floors can each have a "Bedroom": the later one says which floor it is on.
                    'name' => $kept['name'] ?? ($taken ? $label.' · '.$room['name'] : (string) $room['name']),
                    'include' => (bool) ($kept['include'] ?? true),
                    'section_id' => null,
                    'detail' => $this->roomDetail($room, $severalLayouts ? $label : null),
                    'sqft' => is_numeric($room['floor_sqft'] ?? null) ? (float) $room['floor_sqft'] : null,
                    'merged_into' => $kept !== null ? ($kept['merged_into'] ?? null) : $this->roomOfSliver($file, $room, $scanned),
                    'note' => (string) ($kept['note'] ?? ''),
                ];
            }
        }

        // A sliver follows its room into whatever section that room was merged into.
        $rowsByKey = collect($roomRows)->keyBy('key');
        foreach ($roomRows as $index => $row) {
            $roomRows[$index]['merged_into'] = $rowsByKey->get($row['merged_into'] ?? '')['merged_into'] ?? $row['merged_into'];
        }

        // The saved sections show the newest measurement of their rooms — the scan's, when it has one.
        $reused = $this->reusedRun();
        $saved = $reused !== null ? $this->savedSections($reused) : [];
        $others = $previous->where('kind', '!=', 'room')
            ->map(fn (array $row) => ($row['kind'] ?? null) === 'saved' && isset($saved[$row['key']]) ? $this->savedRow($row['key'], $saved[$row['key']], $row) : $row)
            ->values()
            ->all();

        $this->sectionRows = $this->withoutLooseMerges([...$roomRows, ...$others]);
    }

    /**
     * The row key of the room a scanned sliver belongs to: the largest real
     * room of the same scan with the same name once the scan's own "(2)",
     * "(3)" numbering is set aside. Null for a real room, or a sliver with no
     * namesake.
     *
     * @param  array<string, mixed>  $room
     * @param  list<array<string, mixed>>  $scanned
     */
    protected function roomOfSliver(\Livewire\Features\SupportFileUploads\TemporaryUploadedFile $file, array $room, array $scanned): ?string
    {
        if (empty($room['sliver'])) {
            return null;
        }

        $base = fn (array $candidate) => Str::lower(trim((string) preg_replace('/\s*\(\d+\)\s*$/', '', (string) $candidate['name'])));

        $parent = collect($scanned)
            ->filter(fn (array $candidate) => empty($candidate['sliver']) && $base($candidate) === $base($room))
            ->sortByDesc(fn (array $candidate) => (float) ($candidate['floor_sqft'] ?? 0))
            ->first();

        return $parent ? $this->roomKey($file, (string) $parent['name']) : null;
    }

    protected function roomKey(\Livewire\Features\SupportFileUploads\TemporaryUploadedFile $file, string $room): string
    {
        return 'room-'.substr(md5($file->getFilename().'|'.$room), 0, 12);
    }

    /**
     * "234.4 sq.ft. · 17' 4" x 12' 10" · 14 cabinets" — enough to tell a room from a sliver of one.
     *
     * @param  array<string, mixed>  $room
     */
    protected function roomDetail(array $room, ?string $layout = null): string
    {
        return collect([
            $layout,
            is_numeric($room['floor_sqft'] ?? null) ? rtrim(rtrim(number_format((float) $room['floor_sqft'], 1), '0'), '.').' sq.ft.' : null,
            $room['dimensions'] ?? null,
            ! empty($room['cabinet_count']) ? $room['cabinet_count'].' '.Str::plural('cabinet', (int) $room['cabinet_count']) : null,
        ])->filter()->implode(' · ');
    }

    /**
     * The estimate's own sections as rows the draft can add to — unticked,
     * unless the window was opened from one of them.
     *
     * @return list<array{key: string, kind: string, name: string, include: bool, section_id: ?int, detail: string, sqft: ?float, merged_into: ?string}>
     */
    protected function existingSectionRows(?int $ticked = null): array
    {
        return $this->planner()->existingRows($this->estimate, $ticked);
    }

    /** A draft for this estimate that is still going, if there is one. */
    protected function runningDraft(): ?EstimateAiRun
    {
        return $this->runs()->running($this->estimate);
    }

    /** Mark a run nobody has heard from as failed — unless it ended by itself in the meantime. */
    protected function writeOff(EstimateAiRun $run): void
    {
        $this->runs()->writeOff($run);
    }

    /** The run this window follows — only ever one of this estimate's own. */
    protected function run(): ?EstimateAiRun
    {
        return $this->runs()->find($this->estimate, $this->runId);
    }

    /** The draft's life outside the drafting itself — shared with the Hive Contractors app. */
    protected function runs(): EstimateDraftRuns
    {
        return app(EstimateDraftRuns::class);
    }

    protected function planner(): EstimateDraftPlanner
    {
        return app(EstimateDraftPlanner::class);
    }

    /**
     * The lines a run has drafted that are still on the estimate, section by
     * section in the order they were drafted.
     *
     * @return list<array<string, mixed>>
     */
    protected function rowsFor(EstimateAiRun $run): array
    {
        return $this->runs()->lines($this->estimate, $run);
    }

    /** @return Collection<int, string> */
    protected function sectionNames(): Collection
    {
        return $this->runs()->sectionNames($this->estimate);
    }

    /**
     * @param  Collection<int, string>  $sections
     * @return array<string, mixed>
     */
    protected function rowFor(EstimateLineItem $line, Collection $sections): array
    {
        return $this->runs()->row($line, $sections);
    }

    /**
     * A quantity typed in the table is saved to its line straight away; a
     * blank or zero counts as one. A browser that sends the whole list at
     * once ($key null — 2026-10-01 on production, after rows had shifted)
     * changes nothing: the rows are re-read from the estimate instead.
     */
    public function updatedGeneratedItems(mixed $value, ?string $key = null): void
    {
        if ($key === null) {
            $this->reloadDraftLines();

            return;
        }

        [$index, $field] = array_pad(explode('.', $key, 2), 2, null);

        if ($field !== 'quantity' || ! isset($this->generatedItems[$index])) {
            return;
        }

        $this->authorize('update', $this->estimate);

        $line = $this->runs()->setQuantity($this->estimate, $this->run(), $this->generatedItems[$index]['id'] ?? null, $value);
        if ($line === null) {
            return;
        }

        $quantity = (float) $line->quantity;

        // The box shows what was saved: a blank or a zero reads 1, as the line now does.
        $this->generatedItems[$index]['quantity'] = $quantity;
        $this->generatedItems[$index]['total'] = (float) $line->total;
        $this->refreshEstimate();
    }

    /**
     * A line the review may change: one the draft under review itself put on
     * the estimate, and not held by a signature. The rows' ids are
     * client-held; without this any line of the estimate could be named.
     */
    protected function draftedLine(mixed $id): ?EstimateLineItem
    {
        return $this->runs()->draftedLine($this->estimate, $this->run(), $id);
    }

    /**
     * The button passes its line's id as well as its position, and the id
     * wins: removing a line shifts every later position, and this deletes
     * for good (2026-09-30: totals and quantities landing on the next line).
     */
    public function removeItem(int $index, ?int $lineId = null): void
    {
        if ($lineId !== null) {
            $index = array_search($lineId, array_map('intval', array_column($this->generatedItems, 'id')), true);
        }

        if ($index === false || ! isset($this->generatedItems[$index])) {
            return;
        }

        $this->authorize('update', $this->estimate);

        // Never wanted: gone for good, not parked among the restorable lines.
        $this->runs()->removeLine($this->estimate, $this->run(), $this->generatedItems[$index]['id'] ?? null);

        unset($this->generatedItems[$index]);
        $this->generatedItems = array_values($this->generatedItems);
        $this->refreshEstimate();
    }

    /** Everything the draft put in one section, out — and the section with it when the draft made it. */
    public function removeSection(int $sectionId): void
    {
        $this->authorize('update', $this->estimate);

        $run = $this->run();
        if ($run !== null) {
            $this->generatedItems = $this->runs()->removeSection($this->estimate, $run, $sectionId);
        }

        if ($this->generatedItems === []) {
            // Nothing of the draft is left to review.
            $this->discardDraft();

            return;
        }

        $this->refreshEstimate();
    }

    /** Every drafted line off the estimate, the sections the draft brought in with them, and back to the description. */
    public function discardDraft(): void
    {
        $this->authorize('update', $this->estimate);

        $this->runs()->discard($this->estimate, $this->run());

        $this->reset(['generatedItems', 'reasoning', 'error', 'showPreview', 'runId']);
        $this->sectionRows = $this->refreshedSectionRows();
        $this->refreshEstimate();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            heading: 'Draft discarded',
            text: 'The drafted line items were removed from the estimate.',
        );
    }

    /** The draft is on the estimate already; this closes up. */
    public function finish(): void
    {
        $this->authorize('update', $this->estimate);

        $count = count($this->generatedItems);
        $sections = collect($this->generatedItems)->pluck('section_name', 'section_id');

        $this->runs()->finish($this->estimate, $this->run());

        $this->windowOpen = false;

        $this->modal('estimate-ai-generator-modal')->close();
        $this->refreshEstimate();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'AI Estimate Applied',
            text: $count.' line '.($count === 1 ? 'item' : 'items').' added to '.($sections->count() === 1 ? $sections->first() : $sections->count().' sections'),
        );

        $this->reset(['inquiry', 'floorplans', 'floorplanLabels', 'newFloorplans', 'generatedItems', 'reasoning', 'error', 'showPreview', 'runId', 'reusedRunId', 'redoSectionId', 'redoNote']);
        Cache::forget($this->unsentKey());
        $this->sectionRows = $this->existingSectionRows();
    }

    public function startRedo(int $sectionId): void
    {
        $this->redoSectionId = $sectionId;
        $this->redoNote = '';
        $this->resetErrorBag();
    }

    public function cancelRedo(): void
    {
        $this->reset(['redoSectionId', 'redoNote']);
    }

    /**
     * Draft one section of the review again, with a word on what should be
     * different ("full new kitchen with an island") — instead of discarding
     * the whole estimate over one thin section (EstimateDraftRuns::redo).
     * Its review follows.
     */
    public function redoSection(): void
    {
        $this->authorize('update', $this->estimate);
        $this->validate(['redoNote' => 'nullable|string|max:300']);

        $again = $this->redoSectionId === null ? null : $this->runs()->redo($this->estimate, $this->run(), $this->redoSectionId, $this->redoNote, auth()->id());

        if ($again === null) {
            $this->cancelRedo();

            return;
        }

        $this->reset(['redoSectionId', 'redoNote', 'generatedItems', 'reasoning', 'error', 'showPreview']);
        $this->runId = $again->id;
        $this->isGenerating = true;
        $this->windowOpen = true;
        $this->refreshEstimate();
        $this->pollDraft();
    }

    /** Done — and straight on to combining what the rooms have in common (flooring, paint) into sections of their own. */
    public function finishAndCombine(): void
    {
        // The catalog items the draft put in more than one section: ticked for the estimator when the next window opens.
        $repeated = $this->runs()->repeatedItemIds($this->generatedItems);

        $this->finish();
        $this->dispatch('openCombineLineItems', tick: $repeated)->to('estimates.estimate-combine-line-items');
    }

    /** The section rows after the estimate's own sections changed: the scan's and the typed ones kept, the existing ones re-read. */
    protected function refreshedSectionRows(?int $alsoTick = null): array
    {
        $kept = array_values(array_filter($this->sectionRows, fn (array $row) => ($row['kind'] ?? null) !== 'existing'));
        $ticked = collect($this->sectionRows)->where('kind', 'existing')->where('include', true)->pluck('key')->push('section-'.$alsoTick)->all();
        $notes = collect($this->sectionRows)->where('kind', 'existing')->pluck('note', 'key');
        // A section that is still there keeps its tick (rooms may be merged into it) and its note.
        $existing = array_map(fn (array $row) => ['include' => in_array($row['key'], $ticked, true), 'note' => (string) $notes->get($row['key'], '')] + $row, $this->existingSectionRows());

        return $this->withoutLooseMerges([...$kept, ...$existing]);
    }

    // ---- The company's estimating rules: read on every draft, written here. ----

    public function addRule(): void
    {
        $this->authorize('update', $this->estimate);
        $this->validate(['newRule' => 'required|string|min:5|max:500']);

        app(EstimateAiRuleWriter::class)->add($this->vendorId(), $this->newRule, auth()->id());

        $this->newRule = '';
    }

    public function approveRule(int $ruleId): void
    {
        $this->authorize('update', $this->estimate);
        $rule = $this->rule($ruleId);
        $rule && app(EstimateAiRuleWriter::class)->approve($rule);
    }

    public function dismissRule(int $ruleId): void
    {
        $this->authorize('update', $this->estimate);
        $rule = $this->rule($ruleId);
        $rule && app(EstimateAiRuleWriter::class)->dismiss($rule);
    }

    public function deleteRule(int $ruleId): void
    {
        $this->authorize('update', $this->estimate);
        $rule = $this->rule($ruleId);
        $rule && app(EstimateAiRuleWriter::class)->delete($rule);
    }

    protected function rule(int $ruleId): ?EstimateAiRule
    {
        return app(EstimateAiRuleWriter::class)->forVendor($this->vendorId(), $ruleId);
    }

    protected function vendorId(): int
    {
        return (int) ($this->estimate->belongs_to_vendor_id ?? 1);
    }

    /** The edit modal saved or removed a line: re-read the drafted rows. */
    #[On('estimate-line-item-saved')]
    public function reloadDraftLines(): void
    {
        if ($this->generatedItems === []) {
            return;
        }

        $lines = $this->estimate->estimate_line_items()->whereIn('id', array_column($this->generatedItems, 'id'))->get()->keyBy('id');
        $sections = $this->sectionNames();

        $this->generatedItems = collect($this->generatedItems)
            ->filter(fn (array $row) => $lines->has($row['id']))
            ->map(fn (array $row) => $this->rowFor($lines->get($row['id']), $sections))
            ->values()
            ->all();
    }

    protected function refreshEstimate(): void
    {
        $this->dispatch('refreshComponent')->to('estimates.estimate-show');
        $this->dispatch('refresh')->to('projects.project-finances');
    }

    /**
     * Several layouts as one — delegates to FloorplanParser::combineFloorplans().
     * Kept as a public static wrapper: tests call it directly on this class.
     *
     * @param  list<array{label: string, data: array<string, mixed>}>  $layouts
     * @return array<string, mixed>
     */
    public static function combineFloorplans(array $layouts): array
    {
        return FloorplanParser::combineFloorplans($layouts);
    }

    protected function parseFloorplanFile(\Livewire\Features\SupportFileUploads\TemporaryUploadedFile $file): ?array
    {
        return app(FloorplanParser::class)->parseFloorplanFile($file);
    }

    protected function extractFloorplanMetrics(string $text): array
    {
        return app(FloorplanParser::class)->extractFloorplanMetrics($text);
    }

    protected function extractCsvFloorplanMetrics(string $contents): array
    {
        return app(FloorplanParser::class)->extractCsvFloorplanMetrics($contents);
    }

    protected function isPolycamFormat(array $normalizedHeaders): bool
    {
        return app(FloorplanParser::class)->isPolycamFormat($normalizedHeaders);
    }

    protected function extractPolycamMetrics(array $rows): array
    {
        return app(FloorplanParser::class)->extractPolycamMetrics($rows);
    }

    protected function parsePolycamDimensions(string $value): ?array
    {
        return app(FloorplanParser::class)->parsePolycamDimensions($value);
    }

    protected function parsePolycamValue(string $value): ?float
    {
        return app(FloorplanParser::class)->parsePolycamValue($value);
    }

    protected function parsePolycamFeetInches(string $value): ?float
    {
        return app(FloorplanParser::class)->parsePolycamFeetInches($value);
    }

    protected function parseCsvRows(string $contents): array
    {
        return app(FloorplanParser::class)->parseCsvRows($contents);
    }

    protected function normalizeCsvHeader(string $value): string
    {
        return app(FloorplanParser::class)->normalizeCsvHeader($value);
    }

    protected function findCsvColumn(array $headers, array $tokens): ?int
    {
        return app(FloorplanParser::class)->findCsvColumn($headers, $tokens);
    }

    protected function parseCsvNumber($value): ?float
    {
        return app(FloorplanParser::class)->parseCsvNumber($value);
    }

    protected function extractLabeledSqft(string $text, array $labels): ?float
    {
        return app(FloorplanParser::class)->extractLabeledSqft($text, $labels);
    }

    protected function extractLargestDimensions(string $text): ?array
    {
        return app(FloorplanParser::class)->extractLargestDimensions($text);
    }

    protected function estimateWallSqft(float $length, float $width, int $height): float
    {
        return app(FloorplanParser::class)->estimateWallSqft($length, $width, $height);
    }

    public function getEstimatedTotalProperty(): float
    {
        return array_sum(array_map(fn (array $row) => (float) ($row['total'] ?? 0), $this->generatedItems));
    }

    /**
     * What the banner says while a draft runs: the stages of getting started
     * until the first line lands, then which section is being drafted.
     */
    protected function draftStatus(?EstimateAiRun $run): string
    {
        return $this->runs()->status($run, $this->generatedItems);
    }

    /**
     * Listed sections the finished draft put nothing in — they were not
     * added to the estimate, and the review says so.
     *
     * @return list<string>
     */
    protected function sectionsLeftOut(?EstimateAiRun $run): array
    {
        return $this->runs()->notDrafted($run);
    }

    public function render()
    {
        $rules = EstimateAiRule::query()->forVendor($this->vendorId())->whereIn('status', [EstimateAiRule::ACTIVE, EstimateAiRule::PROPOSED])->orderBy('id')->get();
        $run = $this->run();
        $onEstimate = $this->estimate->estimate_sections()->pluck('name')->map(fn ($name) => Str::lower(trim((string) $name)))->filter()->all();
        $draftedSections = collect($this->generatedItems)->pluck('section_name', 'section_id');

        return view('livewire.estimates.estimate-ai-generator', [
            'estimatedTotal' => $this->getEstimatedTotalProperty(),
            'activeRules' => $rules->where('status', EstimateAiRule::ACTIVE)->values(),
            'proposedRules' => $rules->where('status', EstimateAiRule::PROPOSED)->values(),
            'draftStatus' => $this->isGenerating ? $this->draftStatus($run) : '',
            'draftedInto' => $draftedSections->count() === 1 ? $draftedSections->first() : $draftedSections->count().' sections',
            'leftOut' => $this->showPreview ? $this->sectionsLeftOut($run) : [],
            // Names already on the estimate, so a row that would add to one says so.
            'namesOnEstimate' => $onEstimate,
            'earlierRequests' => ! $this->isGenerating && ! $this->showPreview && ! $this->showRules ? $this->earlierRequests() : [],
            'request' => $this->showPreview ? (string) $run?->inquiry : '',
            // A colleague's draft being followed is theirs: "Greg asked", not "You asked".
            'requestBy' => $this->showPreview && $run?->user_id && (int) $run->user_id !== (int) auth()->id() ? trim((string) $run->user?->first_name) : '',
            // Using a request again while its first draft is still on the estimate would add every line a second time.
            'reusedStanding' => ! $this->isGenerating && ! $this->showPreview && ($reused = $this->reusedRun()) !== null
                ? app(EstimateAiDraftWriter::class)->standing($this->estimate, $reused->load('drafts'))['removable']
                : 0,
            // The same catalog item drafted into more than one section: worth offering to combine.
            'repeatsAcrossSections' => $this->showPreview && collect($this->generatedItems)->groupBy('line_item_id')->contains(fn ($rows) => $rows->pluck('section_id')->unique()->count() > 1),
            'newSections' => collect((array) $run?->sections)->whereIn('origin', ['created', 'named'])->pluck('section_id')->filter()->all(),
            'sectionScopes' => collect((array) $run?->sections)->filter(fn (array $section) => ! empty($section['section_id']) && filled($section['scope'] ?? null))->pluck('scope', 'section_id')->all(),
            // What the estimator typed for a section alone, shown with it while it is drafted and in the review.
            'sectionNotes' => $this->runs()->sectionNotes($run),
        ]);
    }
}
