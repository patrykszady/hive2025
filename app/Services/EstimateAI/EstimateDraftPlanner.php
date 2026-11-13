<?php

namespace App\Services\EstimateAI;

use App\Exceptions\Estimates\SectionNotOnEstimate;
use App\Models\Estimate;
use App\Models\EstimateAiRun;
use App\Models\EstimateSection;
use App\Services\EstimateAIService;
use App\Services\Estimates\EstimateAiDraftWriter;
use Illuminate\Support\Str;

/**
 * What a whole-estimate draft is asked to fill, worked out from the rows the
 * estimator ticked — the same on the website's generator window
 * (App\Livewire\Estimates\EstimateAIGenerator) and in the Hive Contractors
 * app (Api\App\V1\EstimateAiRunController), which is why it lives here and
 * not in either.
 *
 * A row is `{key, name, include, section_id, merged_into, note}`. Rows are
 * client-held: a section id is only ever read through the estimate's own
 * sections, and room measurements never come from the rows — the caller
 * hands them in by row key, read from the scans or from a saved request.
 */
class EstimateDraftPlanner
{
    public function __construct(private EstimateAiDraftWriter $writer) {}

    /**
     * The estimate's own sections as rows the draft can add to — unticked,
     * unless one was asked for by id. (A blank, empty section — the one a new
     * estimate starts with — is not offered: the draft's first new section
     * takes it.)
     *
     * @return list<array{key: string, kind: string, name: string, include: bool, section_id: ?int, detail: string, sqft: ?float, merged_into: ?string, note: string}>
     */
    public function existingRows(Estimate $estimate, ?int $ticked = null): array
    {
        return $estimate->estimate_sections()
            ->withCount('estimate_line_items')
            ->orderBy('order')
            ->get()
            ->filter(fn (EstimateSection $section) => (filled($section->name) || $section->estimate_line_items_count > 0) && ! $section->isLocked())
            ->map(fn (EstimateSection $section) => [
                'key' => 'section-'.$section->id,
                'kind' => 'existing',
                'name' => filled($section->name) ? (string) $section->name : 'Unnamed Section',
                'include' => $ticked !== null && $section->id === $ticked,
                'section_id' => $section->id,
                'detail' => $section->estimate_line_items_count.' '.Str::plural('line item', $section->estimate_line_items_count).' · '.money($section->total),
                'sqft' => null,
                'merged_into' => null,
                'note' => '',
            ])
            ->values()
            ->all();
    }

    /**
     * What an earlier request saved, by the row key each comes back under:
     * the sections it listed, then the measured rooms it left out.
     *
     * @return array<string, array{name: string, rooms: list<array<string, mixed>>, listed: bool, note: string}>
     */
    public function savedSections(EstimateAiRun $run): array
    {
        $saved = [];

        foreach (! empty($run->plan['listed']) ? (array) ($run->plan['sections'] ?? []) : [] as $section) {
            $saved[$this->savedKey($run, (string) $section['key'])] = ['name' => (string) $section['name'], 'rooms' => (array) ($section['rooms'] ?? []), 'listed' => true, 'note' => (string) ($section['note'] ?? '')];
        }

        foreach (array_values((array) ($run->plan['left_out'] ?? [])) as $index => $section) {
            $saved[$this->savedKey($run, 'L'.($index + 1))] = ['name' => (string) ($section['name'] ?? 'Room'), 'rooms' => (array) ($section['rooms'] ?? []), 'listed' => false, 'note' => ''];
        }

        return $saved;
    }

    public function savedKey(EstimateAiRun $run, string $sectionKey): string
    {
        return 'saved-'.$run->id.'-'.$sectionKey;
    }

    /**
     * One saved section as a row. Its rooms are the ones the request was
     * drafted from; where an uploaded scan measures a room of the same name,
     * the scan's numbers are the ones shown (and drafted from) — the newest
     * measurement of that room.
     *
     * @param  array{name: string, rooms: list<array<string, mixed>>, listed: bool, note: string}  $section
     * @param  array<string, mixed>|null  $kept  the row as the estimator left it (name, tick, merge, note)
     * @param  array<string, array<string, mixed>>  $scanned  the rooms of the scans uploaded now, by lower-cased name
     * @return array<string, mixed>
     */
    public function savedRow(string $key, array $section, ?array $kept = null, array $scanned = []): array
    {
        $rooms = $this->freshest(array_values(array_filter($section['rooms'], 'is_array')), $scanned);
        $sqft = array_sum(array_map(fn (array $room) => (float) ($room['floor_sqft'] ?? 0), $rooms));

        return [
            'key' => $key,
            'kind' => 'saved',
            'name' => (string) ($kept['name'] ?? $section['name']),
            // Ticked as it was drafted then; a room it left out comes back unticked.
            'include' => (bool) ($kept['include'] ?? $section['listed']),
            'section_id' => null,
            'detail' => collect([
                $sqft > 0 ? rtrim(rtrim(number_format($sqft, 1), '0'), '.').' sq.ft.' : null,
                count($rooms) > 1 ? count($rooms).' rooms together' : ($rooms[0]['dimensions'] ?? null),
                'saved with the earlier request',
            ])->filter()->implode(' · '),
            'sqft' => $sqft > 0 ? $sqft : null,
            'merged_into' => $kept['merged_into'] ?? null,
            'note' => (string) ($kept['note'] ?? $section['note']),
        ];
    }

    /**
     * Saved rooms with an uploaded scan's measurement in place of the saved
     * one wherever the scan has a room of that name.
     *
     * @param  list<array<string, mixed>>  $rooms
     * @param  array<string, array<string, mixed>>  $scanned
     * @return list<array<string, mixed>>
     */
    public function freshest(array $rooms, array $scanned): array
    {
        return array_map(fn (array $room) => $scanned[Str::lower(trim((string) ($room['name'] ?? '')))] ?? $room, $rooms);
    }

    /**
     * The measured rooms behind each saved row of a request being used
     * again, by row key — each room's numbers reduced to what sizes an
     * estimate.
     *
     * @param  array<string, array<string, mixed>>  $scanned
     * @return array<string, list<array<string, mixed>>>
     */
    public function savedRooms(EstimateAiRun $run, array $scanned = []): array
    {
        $rooms = [];

        foreach ($this->savedSections($run) as $key => $section) {
            $rooms[$key] = array_values(array_filter(
                array_map(fn (array $room) => EstimateAIService::roomMetrics($room), $this->freshest(array_values(array_filter((array) ($section['rooms'] ?? []), 'is_array')), $scanned)),
            ));
        }

        return $rooms;
    }

    /**
     * What the draft is asked to fill, from the ticked rows: each section's
     * name, the estimate section it adds to (if it is there already) and the
     * measurements of the rooms put in it. Rows sharing a name are one
     * section. With nothing ticked the model names the sections itself, and
     * what is on the estimate already is offered for it to add to.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, list<array<string, mixed>>>  $rooms  the measured rooms behind each row, by row key
     * @return array{listed: bool, sections: list<array{key: string, name: string, section_id: ?int, on_estimate: bool, rooms: list<array<string, mixed>>, note: ?string}>, left_out: list<array{name: string, rooms: list<array<string, mixed>>}>}
     *
     * @throws SectionNotOnEstimate when a ticked row names a section that is not (or is no longer) one of this estimate's
     */
    public function plan(Estimate $estimate, array $rows, array $rooms): array
    {
        $onEstimate = $estimate->estimate_sections()->orderBy('order')->get()->reject(fn (EstimateSection $section) => $section->isLocked())->values();
        $groups = [];

        $byKey = collect($rows)->keyBy('key');
        $leftOut = [];

        foreach ($rows as $row) {
            // A merged row is drafted in the section it was merged into: that row's name, tick and place.
            $head = empty($row['merged_into']) ? $row : $byKey->get($row['merged_into']);

            if ($head === null || empty($head['include'])) {
                // Measured but not drafted this time: kept with the request, so using it again offers the room.
                if ($head !== null && ($rooms[$row['key'] ?? ''] ?? []) !== []) {
                    $leftOut[$head['key']]['name'] = trim((string) ($head['name'] ?? '')) ?: 'Room';
                    $leftOut[$head['key']]['rooms'] = [...($leftOut[$head['key']]['rooms'] ?? []), ...$rooms[$row['key']]];
                }

                continue;
            }

            // A row's section id is client-held: it is only ever read through
            // this estimate's own sections, so it cannot name another tenant's.
            // One that is not there — another estimate's, or a section removed
            // since the list was shown — stops the draft rather than guessing.
            $section = empty($head['section_id']) ? null : $onEstimate->firstWhere('id', (int) $head['section_id']);

            if (! empty($head['section_id']) && $section === null) {
                throw new SectionNotOnEstimate;
            }

            $name = $section ? (filled($section->name) ? trim((string) $section->name) : 'Unnamed Section') : trim((string) ($head['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $section ??= $onEstimate->first(fn (EstimateSection $candidate) => Str::lower(trim((string) $candidate->name)) === Str::lower($name));
            $group = $section ? 'section:'.$section->id : 'name:'.Str::lower($name);

            $groups[$group] ??= ['name' => $name, 'section_id' => $section?->id, 'rooms' => [], 'notes' => []];

            foreach ($rooms[$row['key'] ?? ''] ?? [] as $room) {
                $groups[$group]['rooms'][] = $room;
            }

            // What the estimator typed for this section alone; rows drafted as one section pool their notes.
            if (filled($row['note'] ?? null)) {
                $groups[$group]['notes'][] = trim((string) $row['note']);
            }
        }

        $listed = $groups !== [];

        if (! $listed) {
            $groups = $onEstimate
                ->filter(fn (EstimateSection $section) => filled($section->name))
                ->map(fn (EstimateSection $section) => ['name' => trim((string) $section->name), 'section_id' => $section->id, 'rooms' => [], 'notes' => []])
                ->all();
        }

        $sections = [];
        foreach (array_values($groups) as $position => $group) {
            $sections[] = [
                'key' => 'S'.($position + 1),
                'name' => $group['name'],
                'section_id' => $group['section_id'],
                'on_estimate' => $group['section_id'] !== null,
                'rooms' => $group['rooms'],
                'note' => implode('; ', array_unique($group['notes'])) ?: null,
            ];
        }

        return ['listed' => $listed, 'sections' => $sections, 'left_out' => array_values($leftOut)];
    }

    /**
     * The requests typed for this estimate before, newest first, each once:
     * what was asked, when and by whom, and whether what it drafted is still
     * on the estimate.
     *
     * @return list<array{id: int, inquiry: string, when: string, by: ?string, outcome: string, on_estimate: int, sections: int}>
     */
    public function earlierRequests(Estimate $estimate): array
    {
        return EstimateAiRun::query()
            ->where('estimate_id', $estimate->id)
            ->whereNotIn('status', [EstimateAiRun::QUEUED, EstimateAiRun::DRAFTING, EstimateAiRun::STOPPING])
            ->with(['user', 'drafts'])
            ->latest('id')
            ->limit(30)
            ->get()
            // One section drafted again is part of its request, not a request of its own.
            ->reject(fn (EstimateAiRun $run) => ! empty($run->plan['closed']))
            ->unique(fn (EstimateAiRun $run) => Str::lower(trim((string) $run->inquiry)))
            ->take(5)
            ->map(function (EstimateAiRun $run) use ($estimate) {
                ['removable' => $lines, 'signed' => $signed] = $this->writer->standing($estimate, $run);
                $sections = count((array) ($run->plan['sections'] ?? []));

                return [
                    'id' => $run->id,
                    'inquiry' => (string) $run->inquiry,
                    'when' => $run->created_at->timezone('America/Chicago')->format('M j, g:i A'),
                    'by' => $run->user ? trim($run->user->first_name.' '.$run->user->last_name) : null,
                    'outcome' => match (true) {
                        $lines > 0 => $lines.' '.Str::plural('line item', $lines).' from it '.($lines === 1 ? 'is' : 'are').' on the estimate'.($signed > 0 ? ', '.$signed.' more in the signed contract' : ''),
                        $signed > 0 => $signed.' '.Str::plural('line item', $signed).' from it '.($signed === 1 ? 'is' : 'are').' in the signed contract',
                        $run->status === EstimateAiRun::FAILED => 'did not finish',
                        $run->status === EstimateAiRun::STOPPED => 'stopped',
                        default => 'its draft is no longer on the estimate',
                    },
                    'on_estimate' => $lines,
                    'sections' => ! empty($run->plan['listed']) ? $sections : 0,
                ];
            })
            ->values()
            ->all();
    }
}
