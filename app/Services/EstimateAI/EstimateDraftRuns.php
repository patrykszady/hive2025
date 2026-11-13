<?php

namespace App\Services\EstimateAI;

use App\Jobs\DraftEstimateSections;
use App\Models\Estimate;
use App\Models\EstimateAiDraft;
use App\Models\EstimateAiRun;
use App\Models\EstimateLineItem;
use App\Services\EstimateAIService;
use App\Services\Estimates\EstimateAiDraftWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The life of a whole-estimate AI draft (one EstimateAiRun) outside the
 * drafting itself: starting it, following it, and the review afterwards —
 * a quantity changed, a line or a section taken out, one section drafted
 * again, Discard, Done. The website's generator window
 * (App\Livewire\Estimates\EstimateAIGenerator) and the Hive Contractors app
 * (Api\App\V1\EstimateAiRunController) both work through this, so a draft
 * started on one can be reviewed on the other.
 *
 * Every method takes the estimate the caller is allowed to change and only
 * ever touches runs, lines and sections that are that estimate's own: the
 * ids callers pass in are client-held.
 */
class EstimateDraftRuns
{
    public function __construct(private EstimateAiDraftWriter $writer, private PlanPictures $plans) {}

    /** One of this estimate's own runs, by id. */
    public function find(Estimate $estimate, ?int $runId): ?EstimateAiRun
    {
        return $runId === null ? null : EstimateAiRun::query()->where('estimate_id', $estimate->id)->find($runId);
    }

    /** A draft for this estimate that is still going, if there is one. */
    public function running(Estimate $estimate): ?EstimateAiRun
    {
        $run = EstimateAiRun::query()
            ->where('estimate_id', $estimate->id)
            ->whereIn('status', [EstimateAiRun::QUEUED, EstimateAiRun::DRAFTING, EstimateAiRun::STOPPING])
            ->latest('id')
            ->first();

        if ($run === null) {
            return null;
        }

        if ($run->hasGoneSilent()) {
            // Its worker is gone, or never took it: written off here so it
            // cannot wake up and draft beside whatever is started next.
            $this->writeOff($run);

            return null;
        }

        return $run;
    }

    /** Mark a run nobody has heard from as failed — unless it ended by itself in the meantime. */
    public function writeOff(EstimateAiRun $run): void
    {
        EstimateAiRun::query()
            ->whereKey($run->id)
            ->whereIn('status', [EstimateAiRun::QUEUED, EstimateAiRun::DRAFTING, EstimateAiRun::STOPPING])
            ->update(['status' => EstimateAiRun::FAILED, 'error' => 'The draft stopped before it finished. Try again.', 'finished_at' => now()]);

        $this->plans->forgetFor($run);
        $run->refresh();
    }

    /**
     * The newest draft of this estimate that ended within the last day and
     * still waits for its Done or Discard — its lines on the estimate, its
     * sections' drafts not yet kept.
     */
    public function unreviewed(Estimate $estimate): ?EstimateAiRun
    {
        $run = EstimateAiRun::query()
            ->where('estimate_id', $estimate->id)
            ->latest('id')
            ->first();

        if ($run === null || $run->isRunning() || $run->finished_at === null || $run->finished_at->lt(now()->subDay())) {
            return null;
        }

        return $run->drafts()->where('status', EstimateAiDraft::DRAFTED)->exists() && $this->lines($estimate, $run) !== [] ? $run : null;
    }

    /**
     * Start a draft: the run is saved and its job queued. Room scans among
     * the layouts are read here; pictures and PDFs are put aside for the job
     * to read (PlanPictures), so the click answers at once.
     *
     * One draft at a time per estimate: looked for and started under a lock,
     * so two people clicking Generate together start one run and the second
     * is handed the first to follow.
     *
     * @param  array<string, mixed>  $plan  as EstimateDraftPlanner::plan() builds it
     * @param  list<UploadedFile>  $files  the layouts uploaded for this draft
     * @param  list<string>  $labels  a name per layout, same position
     * @param  array<string, mixed>|null  $savedFloorplan  the measurements of the request being used again — used when no layout is uploaded now
     * @return array{0: EstimateAiRun, 1: bool}  the run to follow, and whether it was started by this call
     */
    public function start(Estimate $estimate, ?int $userId, string $inquiry, array $plan, array $files = [], array $labels = [], ?array $savedFloorplan = null): array
    {
        $taken = $files !== [] ? $this->plans->take($files, $labels) : ['floorplan' => $savedFloorplan, 'waiting' => []];

        if ($taken['waiting'] !== []) {
            $plan['plans'] = $taken['waiting'];
        }

        $started = false;

        $run = Cache::lock('estimate-ai-run:'.$estimate->id, 15)->block(10, function () use ($estimate, $userId, $inquiry, $plan, $taken, &$started) {
            $running = $this->running($estimate);

            if ($running !== null) {
                return $running;
            }

            $run = EstimateAiRun::create([
                'vendor_id' => (int) ($estimate->belongs_to_vendor_id ?? 1),
                'estimate_id' => $estimate->id,
                'user_id' => $userId,
                'status' => EstimateAiRun::QUEUED,
                'inquiry' => $inquiry,
                'plan' => $plan,
                'floorplan' => EstimateAIService::floorplanMetrics($taken['floorplan']),
            ]);
            $started = true;

            DraftEstimateSections::dispatch($run->id);

            return $run;
        });

        if (! $started) {
            // Someone else's draft is being followed instead: these pictures will never be read.
            $this->plans->forget($taken['waiting']);
        }

        return [$run, $started];
    }

    /** Ask a running draft to stop; what it has drafted so far stays for review. */
    public function stop(Estimate $estimate, ?int $runId): void
    {
        EstimateAiRun::query()
            ->whereKey($runId)
            ->where('estimate_id', $estimate->id)
            ->whereIn('status', [EstimateAiRun::QUEUED, EstimateAiRun::DRAFTING])
            ->update(['status' => EstimateAiRun::STOPPING]);
    }

    /**
     * The lines a run has drafted that are still on the estimate, section by
     * section in the order they were drafted.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(Estimate $estimate, EstimateAiRun $run): array
    {
        $ids = $this->draftedIds($estimate, $run);

        if ($ids->isEmpty()) {
            return [];
        }

        $lines = $estimate->estimate_line_items()->whereIn('id', $ids)->get()->keyBy('id');
        $sections = $this->sectionNames($estimate);

        return $ids
            ->map(fn ($id) => $lines->get((int) $id))
            ->filter()
            ->map(fn (EstimateLineItem $line) => $this->row($line, $sections))
            ->values()
            ->all();
    }

    /** @return Collection<int, string> */
    public function sectionNames(Estimate $estimate): Collection
    {
        return $estimate->estimate_sections()->pluck('name', 'id')->map(fn ($name) => filled($name) ? (string) $name : 'Unnamed Section');
    }

    /**
     * @param  Collection<int, string>  $sections
     * @return array<string, mixed>
     */
    public function row(EstimateLineItem $line, Collection $sections): array
    {
        return [
            'id' => $line->id,
            'line_item_id' => $line->line_item_id,
            'section_id' => (int) $line->section_id,
            'section_name' => $sections->get($line->section_id, 'Section'),
            'name' => $line->name,
            'category' => $line->category,
            'sub_category' => $line->sub_category,
            'quantity' => (float) $line->quantity,
            'unit_type' => $line->unit_type,
            'cost' => (float) $line->cost,
            'total' => (float) $line->total,
        ];
    }

    /**
     * A line the review may change: one the draft under review itself put on
     * the estimate, and not held by a signature. Line ids are client-held;
     * without this any line of the estimate could be named.
     */
    public function draftedLine(Estimate $estimate, ?EstimateAiRun $run, mixed $id): ?EstimateLineItem
    {
        if ($run === null || ! is_numeric($id) || ! $this->draftedIds($estimate, $run)->contains((int) $id)) {
            return null;
        }

        $line = $estimate->estimate_line_items()->find((int) $id);

        return $line !== null && ! $line->section?->isLocked() ? $line : null;
    }

    /**
     * A quantity typed in the review is saved to its line straight away; a
     * blank or zero counts as one.
     */
    public function setQuantity(Estimate $estimate, ?EstimateAiRun $run, mixed $lineId, mixed $value): ?EstimateLineItem
    {
        $line = $this->draftedLine($estimate, $run, $lineId);

        if ($line === null) {
            return null;
        }

        $quantity = is_numeric($value) && (float) $value > 0 ? (float) $value : 1.0;
        $line->quantity = $quantity;
        $line->total = $quantity * (float) $line->cost;
        $line->save();

        return $line;
    }

    /**
     * A no-unit line's price, typed in the review (2026-11-13): the catalog
     * keeps one flat price for these (Painting $40), but the estimator prices
     * them per job (past Painting lines: median $2,000). A blank or negative
     * price counts as nothing. Lines with a unit keep the catalog price here.
     */
    public function setCost(Estimate $estimate, ?EstimateAiRun $run, mixed $lineId, mixed $value): ?EstimateLineItem
    {
        $line = $this->draftedLine($estimate, $run, $lineId);

        if ($line === null || $line->unit_type !== 'no_unit') {
            return null;
        }

        $cost = is_numeric($value) && (float) $value > 0 ? round((float) $value, 2) : 0.0;
        $line->cost = $cost;
        $line->total = (float) $line->quantity * $cost;
        $line->save();

        return $line;
    }

    /** A drafted line never wanted: gone for good, not parked among the restorable lines. */
    public function removeLine(Estimate $estimate, ?EstimateAiRun $run, mixed $lineId): bool
    {
        $line = $this->draftedLine($estimate, $run, $lineId);
        $line?->forceDelete();

        return $line !== null;
    }

    /**
     * Everything the draft put in one section, out — and the section with it
     * when the draft made it.
     *
     * @return list<array<string, mixed>>  what is left of the draft
     */
    public function removeSection(Estimate $estimate, EstimateAiRun $run, int $sectionId): array
    {
        foreach ($this->lines($estimate, $run) as $row) {
            if ((int) $row['section_id'] === $sectionId) {
                $this->draftedLine($estimate, $run, $row['id'])?->forceDelete();
            }
        }

        if (! $run->isRunning()) {
            $this->writer->tidyRun($estimate, $run);
        }

        return $this->lines($estimate, $run);
    }

    /**
     * Every drafted line off the estimate, and the sections the draft
     * brought in with them.
     *
     * @return array{kept_combined: int}
     */
    public function discard(Estimate $estimate, ?EstimateAiRun $run): array
    {
        if ($run === null || $run->isRunning()) {
            return ['kept_combined' => 0];
        }

        return $this->writer->discardRun($estimate, $run);
    }

    /** "Done": the draft is on the estimate already; its sections' drafts are kept, and nothing empty stays behind. */
    public function finish(Estimate $estimate, ?EstimateAiRun $run): void
    {
        if ($run === null) {
            return;
        }

        EstimateAiDraft::query()
            ->where('run_id', $run->id)
            ->where('estimate_id', $estimate->id)
            ->where('status', EstimateAiDraft::DRAFTED)
            ->update(['status' => EstimateAiDraft::FINISHED]);

        // A section the draft made, every line of which was removed in the review, does not stay behind empty.
        $this->writer->tidyRun($estimate, $run);
    }

    /**
     * Draft one section of a reviewed run again, with a word on what should
     * be different ("full new kitchen with an island") — instead of
     * discarding the whole estimate over one thin section. What the draft
     * put in that section comes off; the rest of the draft is kept as Done
     * would keep it; and a new run drafts that section alone, from the same
     * request, the same room measurements and the note.
     *
     * @return EstimateAiRun|null  the new run to follow; null when it cannot be done (the section is signed or gone, or a draft is running)
     */
    public function redo(Estimate $estimate, ?EstimateAiRun $run, int $sectionId, string $note, ?int $userId): ?EstimateAiRun
    {
        $section = $run !== null && ! $run->isRunning() ? $estimate->estimate_sections()->find($sectionId) : null;
        $drafted = collect((array) $run?->sections)->first(fn (array $entry) => (int) ($entry['section_id'] ?? 0) === $sectionId);

        if ($section === null || $drafted === null || $section->isLocked() || $this->running($estimate) !== null) {
            return null;
        }

        // The room measurements and note it was first drafted from, if it was one of the listed sections.
        $planned = collect((array) ($run->plan['sections'] ?? []))->firstWhere('key', $drafted['key'] ?? null) ?? [];
        $note = implode('; ', array_filter([trim((string) ($planned['note'] ?? '')), trim($note)]));

        foreach ($this->lines($estimate, $run) as $row) {
            if ((int) $row['section_id'] === $section->id) {
                $this->draftedLine($estimate, $run, $row['id'])?->forceDelete();
            }
        }

        // That section's first draft was not wanted: nothing is learned from it. The rest is kept.
        $drafts = fn () => EstimateAiDraft::query()->where('run_id', $run->id)->where('estimate_id', $estimate->id)->where('status', EstimateAiDraft::DRAFTED);
        $drafts()->where('section_id', $section->id)->update(['status' => EstimateAiDraft::DISCARDED]);
        $drafts()->update(['status' => EstimateAiDraft::FINISHED]);
        $this->writer->tidyRun($estimate, $run, [$section->id]);

        $again = EstimateAiRun::create([
            'vendor_id' => (int) ($estimate->belongs_to_vendor_id ?? 1),
            'estimate_id' => $estimate->id,
            'user_id' => $userId,
            'status' => EstimateAiRun::QUEUED,
            'inquiry' => (string) $run->inquiry,
            // "closed": this section and no other — the rest of the estimate is drafted already.
            'plan' => [
                'listed' => true,
                'closed' => true,
                'redo_of' => $run->id,
                'sections' => [[
                    'key' => 'S1',
                    'name' => filled($section->name) ? trim((string) $section->name) : 'Unnamed Section',
                    'section_id' => $section->id,
                    'on_estimate' => true,
                    'rooms' => array_values((array) ($planned['rooms'] ?? [])),
                    'note' => $note !== '' ? $note : null,
                ]],
                'left_out' => [],
            ],
            'floorplan' => null,
        ]);

        DraftEstimateSections::dispatch($again->id);

        return $again;
    }

    /**
     * What to say while a draft runs: the stages of getting started until
     * the first line lands, then which section is being drafted.
     *
     * @param  list<array<string, mixed>>  $lines  as lines() gives them
     */
    public function status(?EstimateAiRun $run, array $lines): string
    {
        if ($run === null) {
            return 'Starting…';
        }

        if ($run->status === EstimateAiRun::STOPPING) {
            return 'Stopping…';
        }

        $count = count($lines);

        if ($count === 0) {
            $waited = (int) ($run->started_at ?? $run->created_at)?->diffInSeconds(now());
            $pictures = count(array_filter((array) ($run->plan['plans'] ?? []), fn ($layout) => is_array($layout) && isset($layout['path'])));

            return match (true) {
                // Queued and nothing yet: the background worker has not taken it (busy, or not running).
                $run->status === EstimateAiRun::QUEUED && $run->created_at->diffInSeconds(now()) > 20 => 'Waiting for the background worker to pick this up…',
                $run->status === EstimateAiRun::QUEUED => 'Starting…',
                // Pictures and PDFs of plans are read first; that can take a minute each.
                $pictures > 0 => $pictures === 1 ? 'Reading the plan…' : 'Reading the '.$pictures.' plans…',
                $waited < 6 => 'Reading your description…',
                $waited < 14 => 'Reviewing your line item catalog…',
                $waited < 24 => 'Comparing with your past estimates…',
                default => 'Working out sections and quantities…',
            };
        }

        $sections = collect($lines)->pluck('section_id')->unique()->count();
        $current = collect((array) $run->sections)->last(fn (array $section) => ! empty($section['section_id']))['name'] ?? null;

        return ($current ? "Drafting {$current}… " : 'Drafting… ')
            .$count.' line '.($count === 1 ? 'item' : 'items').' in '.$sections.' '.($sections === 1 ? 'section' : 'sections').' so far';
    }

    /**
     * Listed sections the finished draft put nothing in — they were not
     * added to the estimate, and the review says so.
     *
     * @return list<string>
     */
    public function notDrafted(?EstimateAiRun $run): array
    {
        if ($run === null || $run->isRunning() || empty($run->plan['listed'])) {
            return [];
        }

        $drafted = collect((array) $run->sections)->filter(fn (array $section) => ! empty($section['section_id']))->pluck('key');

        return collect((array) ($run->plan['sections'] ?? []))
            ->reject(fn (array $section) => $drafted->contains($section['key']))
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * The note the estimator typed for each section a run drafted — what
     * went to Claude for that section alone — by estimate section id. Shown
     * with the drafted section while it is written and in the review.
     *
     * @return array<int, string>
     */
    public function sectionNotes(?EstimateAiRun $run): array
    {
        $notes = collect((array) ($run?->plan['sections'] ?? []))
            ->filter(fn ($section) => is_array($section) && filled($section['note'] ?? null))
            ->mapWithKeys(fn (array $section) => [(string) $section['key'] => trim((string) $section['note'])]);

        return collect((array) $run?->sections)
            ->filter(fn ($section) => is_array($section) && ! empty($section['section_id']) && $notes->has((string) ($section['key'] ?? '')))
            ->mapWithKeys(fn (array $section) => [(int) $section['section_id'] => $notes->get((string) $section['key'])])
            ->all();
    }

    /**
     * The catalog items a draft put in more than one section — the flooring,
     * the paint: worth offering to combine.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<int>
     */
    public function repeatedItemIds(array $lines): array
    {
        return collect($lines)
            ->filter(fn (array $row) => ! empty($row['line_item_id']))
            ->groupBy('line_item_id')
            ->filter(fn (Collection $rows) => $rows->pluck('section_id')->unique()->count() > 1)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @return Collection<int, int> the estimate line ids the run's drafts recorded, in drafted order */
    private function draftedIds(Estimate $estimate, EstimateAiRun $run): Collection
    {
        return $run->drafts()->where('estimate_id', $estimate->id)->orderBy('id')->get()
            ->flatMap(fn (EstimateAiDraft $draft) => array_column($draft->drafted_items ?? [], 'estimate_line_item_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();
    }
}
