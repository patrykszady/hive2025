<?php

namespace App\Services\EstimateAI;

use App\Exceptions\Estimates\DraftStopped;
use App\Models\Estimate;
use App\Models\EstimateAiDraft;
use App\Models\EstimateAiRun;
use App\Models\EstimateSection;
use App\Models\User;
use App\Services\EstimateAIService;
use App\Services\Estimates\EstimateSectionWriter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Activitylog\Facades\CauserResolver;

/**
 * Drafts a whole estimate for one EstimateAiRun: asks Claude once
 * (EstimateAIService::generateSections) and, as the reply streams in, puts
 * each line on the estimate under its own section — creating the section
 * the moment its first line lands — while keeping the run row current for
 * the window that is following it.
 *
 * It runs in a queued job (App\Jobs\DraftEstimateSections): a whole estimate
 * takes longer than a web request is allowed. Nothing here depends on a
 * signed-in user.
 */
class EstimateSectionsDrafter
{
    /** Seconds between the heartbeats written while the model is thinking. */
    private const HEARTBEAT_EVERY = 2.0;

    /** @var array<int, array{header: array<string, mixed>, section: ?EstimateSection, closed: bool}> the reply's sections, by position */
    private array $slots = [];

    /** @var array<int, array{draft: EstimateAiDraft, items: list<array<string, mixed>>, origin: string}> what the run drafted, by estimate section id */
    private array $drafted = [];

    private float $lastHeartbeat = 0.0;

    public function __construct(private EstimateAIService $ai, private EstimateSectionWriter $sectionWriter, private PlanPictures $plans) {}

    public function run(EstimateAiRun $run): void
    {
        $estimate = Estimate::withoutGlobalScopes()->find($run->estimate_id);

        // A run that sat in the queue past the point its window gave up on it
        // (the worker was down) must not start drafting later, beside whatever
        // the estimator did in the meantime.
        if ($estimate === null || $run->created_at->diffInSeconds(now()) > EstimateAiRun::SILENT_AFTER_SECONDS) {
            EstimateAiRun::whereKey($run->id)->whereIn('status', [EstimateAiRun::QUEUED, EstimateAiRun::STOPPING])
                ->update(['status' => EstimateAiRun::FAILED, 'error' => 'The draft waited too long to start. Try again.', 'finished_at' => now()]);
            $this->plans->forgetFor($run);

            return;
        }

        // Claimed in one statement: only a run still queued becomes this
        // worker's, so a stop asked for a moment ago is not overwritten and a
        // job delivered twice drafts once.
        $claimed = EstimateAiRun::whereKey($run->id)->where('status', EstimateAiRun::QUEUED)
            ->update(['status' => EstimateAiRun::DRAFTING, 'started_at' => now(), 'heartbeat_at' => now(), 'sections' => '[]', 'error' => null]);

        if ($claimed === 0) {
            // Stopped before it began, or already taken by another worker.
            EstimateAiRun::whereKey($run->id)->where('status', EstimateAiRun::STOPPING)->update(['status' => EstimateAiRun::STOPPED, 'finished_at' => now()]);

            if (! EstimateAiRun::whereKey($run->id)->where('status', EstimateAiRun::DRAFTING)->exists()) {
                // Not another worker's either: nobody will read its plan pictures.
                $this->plans->forgetFor($run);
            }

            return;
        }

        $run->refresh();
        $this->slots = [];
        $this->drafted = [];

        // The estimate's change history names whoever asked for the draft.
        CauserResolver::setCauser($run->user_id ? User::find($run->user_id) : null);

        try {
            $this->readPlans($run);

            $result = $this->ai->generateSections(
                inquiry: (string) $run->inquiry,
                sections: (array) ($run->plan['sections'] ?? []),
                listed: (bool) ($run->plan['listed'] ?? false),
                floorplanData: $run->floorplan,
                vendorId: (int) $run->vendor_id,
                on: [
                    'section' => fn (array $header, int $index) => $this->sectionOpened($run, $header, $index),
                    'line_item' => fn (array $item, int $index) => $this->lineDrafted($run, $estimate, $item, $index),
                    'section_end' => fn (array $header, int $index) => $this->sectionClosed($run, $header, $index),
                    'event' => fn () => $this->pulse($run),
                ],
                // This estimate's own sections are never its examples: a second
                // draft would otherwise copy the first one back.
                excludeSectionIds: EstimateSection::withTrashed()->where('estimate_id', $estimate->id)->pluck('id')->map(fn ($id) => (int) $id)->all(),
                // One section drafted again while the rest of the estimate stands.
                onlyListed: (bool) ($run->plan['closed'] ?? false),
            );

            $this->finish($run, $result['success'] ? EstimateAiRun::FINISHED : EstimateAiRun::FAILED, $result);
        } catch (DraftStopped) {
            // Asked to stop — or the run was written off while this was still
            // going (its window heard nothing for too long): either way what
            // landed is closed out and nothing more is added.
            $stopped = EstimateAiRun::whereKey($run->id)->value('status') === EstimateAiRun::STOPPING;
            $this->finish($run, $stopped ? EstimateAiRun::STOPPED : EstimateAiRun::FAILED, ['error' => $run->fresh()?->error ?? 'The draft stopped before it finished. Try again.']);
        } catch (\Throwable $e) {
            Log::channel('estimate_ai')->error('Estimate sections draft failed', ['run_id' => $run->id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            report($e);

            $this->finish($run, EstimateAiRun::FAILED, ['error' => 'The draft stopped unexpectedly. Try again.']);
        } finally {
            CauserResolver::setCauser(null);
        }
    }

    /**
     * Plan pictures and PDFs put aside when Generate was clicked
     * (PlanPictures::take) are read here, before the model is asked: the run
     * then carries the measurements of every layout, and the files are gone.
     * A stop asked for while they are read ends the run like any other.
     */
    protected function readPlans(EstimateAiRun $run): void
    {
        $waiting = (array) ($run->plan['plans'] ?? []);

        if ($waiting === []) {
            return;
        }

        $floorplan = null;

        try {
            $floorplan = $this->plans->read($waiting, function () use ($run) {
                $this->stopIfAsked($run);
                $this->heartbeat($run);
            });
        } finally {
            $this->plans->forget($waiting);

            $run->forceFill([
                'plan' => array_diff_key((array) $run->plan, ['plans' => true]),
                // What the scans said at the click stands if the pictures added nothing.
                'floorplan' => EstimateAIService::floorplanMetrics($floorplan) ?? $run->floorplan,
                'heartbeat_at' => now(),
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $header
     */
    protected function sectionOpened(EstimateAiRun $run, array $header, int $index): void
    {
        $this->stopIfAsked($run);
        $this->slots[$index] = ['header' => $header, 'section' => null, 'closed' => false];
        $this->saveProgress($run);
    }

    /**
     * One drafted item onto the estimate. The section it belongs to is
     * resolved — and, when it is a new one, made — only now, so a section the
     * model opened and left empty never reaches the estimate.
     *
     * @param  array<string, mixed>  $item
     */
    protected function lineDrafted(EstimateAiRun $run, Estimate $estimate, array $item, int $index): void
    {
        $this->stopIfAsked($run);

        if (! isset($this->slots[$index])) {
            return;
        }

        $firstLine = $this->slots[$index]['section'] === null;
        $section = $this->slots[$index]['section'] ??= $this->sectionFor($run, $estimate, $this->slots[$index]['header']);
        $line = $this->ai->applyLineItem($estimate, $section, $item);

        if ($line !== null) {
            $this->drafted[$section->id]['items'][] = [
                'estimate_line_item_id' => $line->id,
                'line_item_id' => $line->line_item_id,
                'name' => $line->name,
                'quantity' => (float) $line->quantity,
                'unit_type' => $line->unit_type,
                'cost' => (float) $line->cost,
            ];
            $this->drafted[$section->id]['draft']->forceFill(['drafted_items' => $this->drafted[$section->id]['items']])->save();
        }

        $firstLine ? $this->saveProgress($run) : $this->heartbeat($run);
    }

    /**
     * @param  array<string, mixed>  $header
     */
    protected function sectionClosed(EstimateAiRun $run, array $header, int $index): void
    {
        if (! isset($this->slots[$index])) {
            return;
        }

        $this->slots[$index]['header'] = $header;
        $this->slots[$index]['closed'] = true;
        $this->saveProgress($run);
    }

    /**
     * The estimate section a drafted section lands in: the one the estimator
     * ticked; else one already carrying that name; else the empty, unnamed
     * section a new estimate starts with, given the name; else a new one. A
     * section locked by a signature is never added to — a new one is made
     * beside it, as a change order would be.
     *
     * @param  array<string, mixed>  $header
     */
    protected function sectionFor(EstimateAiRun $run, Estimate $estimate, array $header): EstimateSection
    {
        $listed = collect((array) ($run->plan['sections'] ?? []))->firstWhere('key', $header['key'] ?? null);
        $name = trim((string) ($header['name'] ?? '')) ?: 'Section';
        $onEstimate = $estimate->estimate_sections()->orderBy('order')->get();
        $open = $onEstimate->reject(fn (EstimateSection $section) => $section->isLocked());

        $section = ($listed['section_id'] ?? null) ? $open->firstWhere('id', (int) $listed['section_id']) : null;
        $section ??= $open->first(fn (EstimateSection $candidate) => Str::lower(trim((string) $candidate->name)) === Str::lower($name));
        $origin = 'existing';

        if ($section === null) {
            $blank = $open->first(fn (EstimateSection $candidate) => blank($candidate->name)
                && ! $candidate->estimate_line_items()->withTrashed()->exists()
                && ! isset($this->drafted[$candidate->id]));

            if ($blank !== null) {
                $blank->forceFill(['name' => $name])->save();
                [$section, $origin] = [$blank, 'named'];
            } else {
                [$section, $origin] = [$this->sectionWriter->addSection($estimate, $name), 'created'];
            }
        }

        $this->drafted[$section->id] ??= [
            'draft' => EstimateAiDraft::create([
                'vendor_id' => $run->vendor_id,
                'estimate_id' => $estimate->id,
                'section_id' => $section->id,
                'run_id' => $run->id,
                'user_id' => $run->user_id,
                'inquiry' => (string) $run->inquiry,
                'floorplan' => $header['measurements'] ?? null,
                'reasoning' => filled($header['scope'] ?? null) ? $header['scope'] : null,
                'drafted_items' => [],
                'status' => EstimateAiDraft::DRAFTED,
            ]),
            'items' => [],
            'origin' => $origin,
        ];

        return $section;
    }

    /**
     * The run's end, however it came: every section keeps how its scope was
     * read, the drafts take the call's details, and the run says how it went.
     * What was drafted before a stop or a failure stays on the estimate.
     *
     * @param  array<string, mixed>  $result
     */
    protected function finish(EstimateAiRun $run, string $status, array $result = []): void
    {
        $meta = (array) ($result['meta'] ?? []);
        $reasoning = trim((string) ($result['reasoning'] ?? ''));
        $alone = count($this->drafted) === 1;
        $planned = collect((array) ($run->plan['sections'] ?? []))->keyBy('key');

        foreach ($this->slots as $slot) {
            if ($slot['section'] === null) {
                continue;
            }

            $scope = filled($slot['header']['scope'] ?? null) ? (string) $slot['header']['scope'] : null;
            $origin = $this->drafted[$slot['section']->id]['origin'];
            $note = trim((string) ($planned->get($slot['header']['key'] ?? '')['note'] ?? ''));

            // A section drafted on its own remembers the enquiry, as it always
            // has. One of several keeps only its own scope: the enquiry there
            // describes every room of the job, not this one — but a section
            // that was on the estimate before keeps the enquiry it already had.
            $slot['section']->forceFill([
                'ai_inquiry' => match (true) {
                    $alone => (string) $run->inquiry,
                    $origin === 'existing' => $slot['section']->ai_inquiry,
                    default => null,
                },
                'ai_scope' => $scope ?? ($alone && $reasoning !== '' ? $reasoning : null),
                // The estimator's own note for this section stays with it, as typed
                // (2026-11-13) — what went to Claude beside the scope Claude wrote
                // back. A section added to without a note keeps the one it had.
                'ai_note' => $note !== '' ? $note : ($origin === 'existing' ? $slot['section']->ai_note : null),
            ])->save();

            $this->drafted[$slot['section']->id]['draft']->forceFill([
                'reasoning' => $scope ?? ($alone && $reasoning !== '' ? $reasoning : null),
            ]);
        }

        // Totals re-read once the lines are in, rather than trusted to the
        // line-by-line adjustments made while they landed.
        foreach (array_keys($this->drafted) as $sectionId) {
            $section = EstimateSection::query()->find($sectionId);

            if ($section !== null) {
                $section->total = $section->estimate_line_items()->sum('total');
                $section->save();

                if ($section->bid) {
                    $section->bid->amount = EstimateSection::query()->where('bid_id', $section->bid_id)->sum('total');
                    $section->bid->save();
                }
            }
        }

        foreach ($this->drafted as $entry) {
            $entry['draft']->forceFill([
                'request_id' => $meta['request_id'] ?? null,
                'model' => $meta['model'] ?? null,
                'stop_reason' => $meta['stop_reason'] ?? null,
                'floorplan' => $entry['draft']->floorplan ?? ($alone ? $run->floorplan : null),
            ])->save();
        }

        $run->forceFill([
            'status' => $status,
            'request_id' => $meta['request_id'] ?? $run->request_id,
            'model' => $meta['model'] ?? $run->model,
            'usage' => $meta['usage'] ?? null,
            'stop_reason' => $meta['stop_reason'] ?? null,
            'reasoning' => $reasoning !== '' ? $reasoning : null,
            'error' => $status === EstimateAiRun::FAILED ? ($result['error'] ?? 'The draft could not be finished.') : null,
            'sections' => $this->progress(finished: true),
            'heartbeat_at' => now(),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * The reply's sections so far, for the window following the run and for
     * undoing the draft: where each landed and whether the run made that
     * section ("created"), named an empty one ("named") or added to one that
     * was there ("existing").
     *
     * @return list<array<string, mixed>>
     */
    protected function progress(bool $finished = false): array
    {
        $progress = [];

        foreach ($this->slots as $slot) {
            $sectionId = $slot['section']?->id;

            $progress[] = [
                'key' => $slot['header']['key'] ?? null,
                'name' => $slot['header']['name'] ?? null,
                'scope' => filled($slot['header']['scope'] ?? null) ? $slot['header']['scope'] : null,
                'section_id' => $sectionId,
                'origin' => $sectionId ? $this->drafted[$sectionId]['origin'] : null,
                'draft_id' => $sectionId ? $this->drafted[$sectionId]['draft']->id : null,
                'done' => $finished || $slot['closed'],
            ];
        }

        return $progress;
    }

    protected function saveProgress(EstimateAiRun $run): void
    {
        $this->lastHeartbeat = microtime(true);
        $run->forceFill(['sections' => $this->progress(), 'heartbeat_at' => now()])->save();
    }

    protected function heartbeat(EstimateAiRun $run): void
    {
        $this->lastHeartbeat = microtime(true);
        $run->forceFill(['heartbeat_at' => now()])->save();
    }

    /** Called on every stream event, thinking included: a sign of life now and then, and a look for a stop. */
    protected function pulse(EstimateAiRun $run): void
    {
        if (microtime(true) - $this->lastHeartbeat < self::HEARTBEAT_EVERY) {
            return;
        }

        $this->stopIfAsked($run);
        $this->heartbeat($run);
    }

    /**
     * Carry on only while the run is still this worker's to draft: a stop
     * asked for, or the run written off by the window that was following it,
     * ends the draft here.
     */
    protected function stopIfAsked(EstimateAiRun $run): void
    {
        if (EstimateAiRun::whereKey($run->id)->value('status') !== EstimateAiRun::DRAFTING) {
            throw new DraftStopped('The draft was stopped.');
        }
    }
}
