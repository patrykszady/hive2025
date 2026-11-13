<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Exceptions\Estimates\SectionNotOnEstimate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StartEstimateAiRunRequest;
use App\Models\Estimate;
use App\Models\EstimateAiRun;
use App\Models\User;
use App\Services\EstimateAI\EstimateDraftPlanner;
use App\Services\EstimateAI\EstimateDraftRuns;
use App\Services\Estimates\EstimateAiDraftWriter;
use App\Services\Estimates\FloorplanParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Whole-estimate AI drafting for the Hive Contractors app — the website's
 * generator window (App\Livewire\Estimates\EstimateAIGenerator) as
 * endpoints. The app starts a run and polls it while a queued job
 * (App\Jobs\DraftEstimateSections) puts the lines on the estimate; the
 * review afterwards changes those real lines.
 *
 * Nothing is decided here: what a draft is asked to fill comes from
 * App\Services\EstimateAI\EstimateDraftPlanner and every step of a run's
 * life from App\Services\EstimateAI\EstimateDraftRuns — the two services the
 * website works through as well — so a draft started in the app can be
 * reviewed on the website and the other way round.
 *
 * Every id in a request is client-held: runs, lines and sections are only
 * ever read through the estimate the user may update.
 */
class EstimateAiRunController extends Controller
{
    public function __construct(private EstimateDraftRuns $runs, private EstimateDraftPlanner $planner) {}

    /** What the screen needs when it opens: the sections to add to, a draft to follow or review, the earlier requests. */
    public function show(Request $request, int $estimate): JsonResponse
    {
        $found = $this->editableEstimate($estimate);
        $run = $this->runs->running($found) ?? $this->runs->unreviewed($found);

        return response()->json([
            'data' => [
                'sections' => $this->sectionOptions($found),
                'run' => $run ? $this->present($found, $run, $request->user()) : null,
                'earlier_requests' => $this->planner->earlierRequests($found),
            ],
        ]);
    }

    /** "Use again": an earlier request's text and the sections saved with it. */
    public function request(int $estimate, int $run, EstimateAiDraftWriter $writer): JsonResponse
    {
        $found = $this->editableEstimate($estimate);
        $saved = $this->ownRun($found, $run);
        $sections = $this->planner->savedSections($saved);

        return response()->json([
            'data' => [
                'id' => $saved->id,
                'inquiry' => (string) $saved->inquiry,
                'sections' => collect($sections)
                    ->map(fn (array $section, string $key) => Arr::only($this->planner->savedRow($key, $section), ['key', 'name', 'include', 'note', 'detail', 'sqft']))
                    ->values()
                    ->all(),
                'has_measurements' => filled($saved->floorplan) || collect($sections)->contains(fn (array $section) => $section['rooms'] !== []),
                // Lines of its earlier draft still on the estimate: generating again would add each a second time.
                'standing' => $writer->standing($found, $saved->load('drafts'))['removable'],
            ],
        ]);
    }

    /** An earlier request's draft off the estimate — Discard, for a draft already closed with Done. */
    public function removeDraft(int $estimate, int $run, EstimateAiDraftWriter $writer): JsonResponse
    {
        $found = $this->editableEstimate($estimate);
        $earlier = $this->ownRun($found, $run);

        if ($earlier->isRunning()) {
            return response()->json(['message' => 'That draft is still being written.'], 422);
        }

        $signed = $writer->standing($found, $earlier->load('drafts'))['signed'];
        $result = $writer->discardRun($found, $earlier);

        return response()->json(['data' => ['removed' => true, 'signed' => $signed, 'kept_combined' => $result['kept_combined']]]);
    }

    /** "Generate Estimate". */
    public function store(StartEstimateAiRunRequest $request, int $estimate, FloorplanParser $parser): JsonResponse
    {
        $found = $this->editableEstimate($estimate);
        $files = array_values(array_filter(Arr::wrap($request->file('floorplans')), fn ($file) => $file instanceof UploadedFile));
        $reused = $this->runs->find($found, $request->integer('reuse_run_id') ?: null);

        try {
            $plan = $this->planner->plan(
                $found,
                $request->rows(),
                // Measurements never come from the rows: a "saved-…" row stands for the rooms saved with the request being used again.
                $reused !== null ? $this->planner->savedRooms($reused, $this->roomsIn($files, $parser)) : [],
            );
        } catch (SectionNotOnEstimate $stale) {
            return response()->json(['message' => $stale->getMessage(), 'sections' => $this->sectionOptions($found)], 422);
        }

        [$run, $started] = $this->runs->start(
            $found,
            $request->user()->id,
            (string) $request->validated('inquiry'),
            $plan,
            $files,
            array_map('strval', (array) ($request->validated('labels') ?? [])),
            $reused?->floorplan,
        );

        // A draft already being written for this estimate is followed, not doubled: 200 with that one, and "joined".
        return response()->json(['data' => $this->present($found, $run->fresh(), $request->user()), 'joined' => ! $started], $started ? 201 : 200);
    }

    public function run(Request $request, int $estimate, int $run): JsonResponse
    {
        $found = $this->editableEstimate($estimate);
        $followed = $this->ownRun($found, $run);

        if ($followed->hasGoneSilent()) {
            // Its worker is gone (a deploy, a crash): nothing more will come.
            $this->runs->writeOff($followed);
        }

        return response()->json(['data' => $this->present($found, $followed, $request->user())]);
    }

    public function stop(Request $request, int $estimate, int $run): JsonResponse
    {
        $found = $this->editableEstimate($estimate);
        $followed = $this->ownRun($found, $run);

        $this->runs->stop($found, $followed->id);

        return response()->json(['data' => $this->present($found, $followed->fresh(), $request->user())]);
    }

    /**
     * A quantity and/or — on a no-unit line (Painting, Demo), whose catalog
     * price is set per job — a price, typed in the review and saved at once.
     */
    public function updateLine(Request $request, int $estimate, int $run, int $line): JsonResponse
    {
        $found = $this->editableEstimate($estimate);
        $reviewed = $this->reviewableRun($found, $run);
        $request->validate(['quantity' => ['nullable'], 'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999']]);

        abort_unless($request->has('quantity') || $request->has('cost'), 422, 'Send a quantity or a price.');

        if ($request->has('quantity')) {
            abort_if($this->runs->setQuantity($found, $reviewed, $line, $request->input('quantity')) === null, 404);
        }

        if ($request->has('cost')) {
            $drafted = $this->runs->draftedLine($found, $reviewed, $line);
            abort_if($drafted === null, 404);
            abort_if($drafted->unit_type !== 'no_unit', 422, 'Only a no-unit line\'s price is set here.');
            $this->runs->setCost($found, $reviewed, $line, $request->input('cost'));
        }

        return response()->json(['data' => $this->present($found, $reviewed, $request->user())]);
    }

    public function destroyLine(Request $request, int $estimate, int $run, int $line): JsonResponse
    {
        $found = $this->editableEstimate($estimate);
        $reviewed = $this->reviewableRun($found, $run);

        abort_unless($this->runs->removeLine($found, $reviewed, $line), 404);

        return response()->json(['data' => $this->present($found, $reviewed, $request->user())]);
    }

    /** Everything the draft put in one section, out. With nothing of the draft left, the draft is discarded. */
    public function destroySection(Request $request, int $estimate, int $run, int $section): JsonResponse
    {
        $found = $this->editableEstimate($estimate);
        $reviewed = $this->reviewableRun($found, $run);

        abort_unless(collect($this->runs->lines($found, $reviewed))->contains(fn (array $row) => (int) $row['section_id'] === $section), 404);

        if ($this->runs->removeSection($found, $reviewed, $section) === []) {
            $this->runs->discard($found, $reviewed);
        }

        return response()->json(['data' => $this->present($found, $reviewed->fresh(), $request->user())]);
    }

    /** One section drafted again, with a word on what should be different; the rest of the draft is kept. */
    public function redoSection(Request $request, int $estimate, int $run, int $section): JsonResponse
    {
        $found = $this->editableEstimate($estimate);
        $reviewed = $this->reviewableRun($found, $run);
        $note = (string) ($request->validate(['note' => ['nullable', 'string', 'max:300']])['note'] ?? '');

        $again = $this->runs->redo($found, $reviewed, $section, $note, $request->user()->id);

        if ($again === null) {
            return response()->json(['message' => 'That section cannot be drafted again right now.'], 422);
        }

        return response()->json(['data' => $this->present($found, $again->fresh(), $request->user())], 201);
    }

    public function discard(int $estimate, int $run): JsonResponse
    {
        $found = $this->editableEstimate($estimate);
        $reviewed = $this->reviewableRun($found, $run);

        return response()->json(['data' => ['discarded' => true] + $this->runs->discard($found, $reviewed)]);
    }

    /** "Done": the draft stays on the estimate. */
    public function finish(int $estimate, int $run): JsonResponse
    {
        $found = $this->editableEstimate($estimate);
        $reviewed = $this->reviewableRun($found, $run);
        $lines = $this->runs->lines($found, $reviewed);

        $this->runs->finish($found, $reviewed);

        return response()->json([
            'data' => [
                'finished' => true,
                'line_count' => count($lines),
                'section_count' => collect($lines)->pluck('section_id')->unique()->count(),
                'repeated_item_ids' => $this->runs->repeatedItemIds($lines),
            ],
        ]);
    }

    /**
     * A run as the app shows it, running or under review.
     *
     * @return array<string, mixed>
     */
    private function present(Estimate $estimate, EstimateAiRun $run, ?User $viewer): array
    {
        $lines = $this->runs->lines($estimate, $run);
        $progress = collect((array) $run->sections)->filter(fn ($entry) => is_array($entry) && ! empty($entry['section_id']))->keyBy(fn (array $entry) => (int) $entry['section_id']);
        $running = $run->isRunning();
        $notes = $this->runs->sectionNotes($run);

        return [
            'id' => $run->id,
            'status' => (string) $run->status,
            'running' => $running,
            'status_text' => $running ? $this->runs->status($run, $lines) : '',
            'inquiry' => (string) $run->inquiry,
            // A colleague's draft being followed is theirs: "Greg asked", not "You asked".
            'asked_by' => $run->user_id && (int) $run->user_id !== (int) $viewer?->id ? (trim((string) $run->user?->first_name) ?: null) : null,
            'error' => match (true) {
                $running => null,
                $run->status === EstimateAiRun::FAILED => $run->error ?: 'The draft could not be finished.',
                $run->status === EstimateAiRun::STOPPED => 'The draft was stopped.',
                $lines === [] => 'Nothing was drafted. Describe the work in more detail and try again.',
                default => null,
            },
            'reasoning' => $running ? '' : (string) $run->reasoning,
            'redo_of' => isset($run->plan['redo_of']) ? (int) $run->plan['redo_of'] : null,
            'line_count' => count($lines),
            'total' => round((float) collect($lines)->sum('total'), 2),
            'sections' => collect($lines)
                ->groupBy('section_id')
                ->map(fn (Collection $rows, int|string $sectionId) => [
                    'section_id' => (int) $sectionId,
                    'name' => (string) $rows->first()['section_name'],
                    'scope' => filled($progress->get((int) $sectionId)['scope'] ?? null) ? (string) $progress->get((int) $sectionId)['scope'] : null,
                    // What the estimator typed for this section alone — the note that went to Claude for it.
                    'note' => $notes[(int) $sectionId] ?? null,
                    // The draft brought this section in — made it, or named the blank one a new estimate starts with.
                    'is_new' => in_array($progress->get((int) $sectionId)['origin'] ?? null, ['created', 'named'], true),
                    'total' => round((float) $rows->sum('total'), 2),
                    'lines' => $rows->map(fn (array $row) => Arr::except($row, ['section_id', 'section_name']))->values()->all(),
                ])
                ->values()
                ->all(),
            'not_drafted' => $this->runs->notDrafted($run),
            'repeated_item_ids' => $running ? [] : $this->runs->repeatedItemIds($lines),
        ];
    }

    /**
     * The estimate's own sections a draft can add to.
     *
     * @return list<array{key: string, section_id: int, name: string, detail: string}>
     */
    private function sectionOptions(Estimate $estimate): array
    {
        return array_map(
            fn (array $row) => Arr::only($row, ['key', 'section_id', 'name', 'detail']),
            $this->planner->existingRows($estimate),
        );
    }

    /**
     * Every room the uploaded scans measure, by its name as scanned
     * (lower-cased): a saved section is drafted at the newest measurement of
     * its rooms.
     *
     * @param  list<UploadedFile>  $files
     * @return array<string, array<string, mixed>>
     */
    private function roomsIn(array $files, FloorplanParser $parser): array
    {
        $rooms = [];

        foreach ($files as $file) {
            foreach ($parser->rooms($file) as $room) {
                $rooms[Str::lower(trim((string) $room['name']))] ??= $room;
            }
        }

        return $rooms;
    }

    /** One of this estimate's own runs, whatever the id in the address says. */
    private function ownRun(Estimate $estimate, int $runId): EstimateAiRun
    {
        $run = $this->runs->find($estimate, $runId);
        abort_if($run === null, 404);

        return $run;
    }

    /** A run whose drafting has ended: only then can its lines be reviewed. */
    private function reviewableRun(Estimate $estimate, int $runId): EstimateAiRun
    {
        $run = $this->ownRun($estimate, $runId);
        abort_if($run->isRunning(), 422, 'The draft is still being written.');

        return $run;
    }

    /** The estimate, visible to this user's company and theirs to change — the rule every estimate edit in the app goes by. */
    private function editableEstimate(int $id): Estimate
    {
        abort_unless(Estimate::withoutGlobalScopes()->whereKey($id)->exists(), 404);
        $found = Estimate::query()->whereKey($id)->first();
        abort_unless($found, 403, 'You do not have access to this estimate.');

        $this->authorize('update', $found);

        return $found;
    }
}
