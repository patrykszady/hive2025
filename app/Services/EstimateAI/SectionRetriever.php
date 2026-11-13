<?php

namespace App\Services\EstimateAI;

use App\Models\EstimateSection;
use App\Models\EstimateSectionEmbedding;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The past sections of a company's own estimates that are closest to a new
 * enquiry: by meaning when embeddings are available, by word overlap when
 * they are not, and the most recent sections when nothing matches at all.
 * Only the vendor's own history is ever searched.
 */
class SectionRetriever
{
    /** Latest sections considered per company. */
    public const CANDIDATES = 300;

    /** Sections embedded per request to the embeddings API. */
    public const BATCH = 64;

    /** Latest named sections whose names are compared when looking for namesakes. */
    public const NAMED_CANDIDATES = 3000;

    /**
     * The words that say which room a section is (as tokens() reads them).
     * Two names are only alike when they name the same rooms: "Hallway" is
     * not "Hall Bath", "Basement" is not "Basement Bath", "Kitchen" is not
     * "Kitchen / Family Rm". Words outside this list ("hall", "primary",
     * "basement", "guest") only tell rooms of a kind apart.
     */
    private const ROOM_WORDS = ['bath', 'kitchen', 'bedroom', 'mud', 'living', 'dining', 'foyer', 'office', 'garage', 'closet', 'pantry', 'bar', 'stair', 'staircase', 'deck', 'porch', 'attic', 'shower'];

    /** Words in a section name that name no room or trade in particular. */
    private const GENERIC_NAME_WORDS = ['other', 'misc', 'area', 'space', 'main', 'level', 'part', 'side', '1st', '2nd', '3rd', 'first', 'second', 'upstair', 'downstair'];

    private const SYNONYMS = [
        'bathroom' => 'bath', 'baths' => 'bath', 'washroom' => 'bath', 'powder' => 'bath',
        'master' => 'primary', 'ensuite' => 'primary',
        'mudroom' => 'mud', 'laundry' => 'mud',
        'family' => 'living', 'lounge' => 'living', 'den' => 'living', 'sitting' => 'living', 'great' => 'living',
        'entry' => 'foyer', 'entryway' => 'foyer', 'hallway' => 'hall',
        'cellar' => 'basement', 'lower' => 'basement',
        'reno' => 'remodel', 'renovation' => 'remodel', 'remodeling' => 'remodel', 'rehab' => 'remodel',
        'tiles' => 'tile', 'tiling' => 'tile', 'flooring' => 'floor', 'floors' => 'floor',
        'cabinetry' => 'cabinet', 'cabinets' => 'cabinet', 'countertops' => 'counter', 'countertop' => 'counter',
        'lighting' => 'light', 'lights' => 'light', 'electric' => 'electrical',
        'plumbing' => 'plumb', 'painting' => 'paint', 'drywalling' => 'drywall',
    ];

    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'this', 'that', 'from', 'into', 'new', 'all', 'our', 'your', 'their', 'they', 'them',
        'are', 'was', 'were', 'have', 'has', 'had', 'will', 'would', 'like', 'want', 'wants', 'need', 'needs', 'also',
        'please', 'thanks', 'thank', 'hello', 'hi', 'you', 'we', 'us', 'it', 'its', 'one', 'two', 'per', 'about', 'some',
        'address', 'phone', 'email', 'project', 'section', 'estimate', 'job', 'work', 'room',
    ];

    public function __construct(private EmbeddingService $embeddings) {}

    /**
     * @param  list<int>  $excludeSectionIds
     * @return list<array{section: EstimateSection, score: float}>
     */
    public function similar(int $vendorId, string $inquiry, int $limit = 4, array $excludeSectionIds = []): array
    {
        $candidates = $this->candidates($vendorId, $excludeSectionIds);

        if ($candidates->isEmpty()) {
            return [];
        }

        $scores = $this->semanticScores($vendorId, $inquiry, $candidates) ?? $this->lexicalScores($inquiry, $candidates);

        $ranked = $candidates
            ->map(fn (EstimateSection $section) => ['section' => $section, 'score' => round($scores[$section->id] ?? 0.0, 4)])
            ->filter(fn (array $row) => $row['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();

        if ($ranked !== []) {
            return $ranked;
        }

        // Nothing resembles the enquiry: the latest sections are still the best picture of how this company estimates.
        return $candidates->take($limit)->map(fn (EstimateSection $section) => ['section' => $section, 'score' => 0.0])->values()->all();
    }

    /**
     * The company's own past sections that carry the same or a similar name,
     * for each name asked for — "Kitchen" finds its Kitchen sections, then
     * "Kitchen / Family Rm"; "Laundry" finds "Laundry Room" and "Mud Room"
     * (2026-11-13: a drafted kitchen was shown no past kitchen at all and
     * left out most of what this company's kitchens carry). The closer name
     * comes first; among equal names, the section from the job most like
     * this enquiry. A name with no meaningful word ("New room (2)") finds
     * nothing.
     *
     * @param  array<string, string>  $names  key => section name
     * @param  list<int>  $excludeSectionIds
     * @return array<string, list<array{section: EstimateSection, score: float, name_score: float}>> key => best namesakes first
     */
    public function namesakes(int $vendorId, array $names, string $inquiry, int $perName = 3, array $excludeSectionIds = []): array
    {
        $wanted = array_filter(array_map(fn ($name) => self::nameTokens((string) $name), $names));

        if ($wanted === []) {
            return [];
        }

        $named = EstimateSection::query()
            ->whereHas('estimate', fn ($q) => $q->withoutGlobalScopes()->whereNull('deleted_at')->where('belongs_to_vendor_id', $vendorId))
            ->whereHas('estimate_line_items')
            ->where('name', '!=', '')
            ->where('name', 'not like', '%change order%')
            ->when($excludeSectionIds !== [], fn ($q) => $q->whereNotIn('id', $excludeSectionIds))
            ->latest('id')
            ->limit(self::NAMED_CANDIDATES)
            ->get(['id', 'name']);

        $matches = [];
        foreach ($wanted as $key => $tokens) {
            foreach ($named as $section) {
                $score = self::nameMatch($tokens, self::nameTokens((string) $section->name));
                if ($score > 0) {
                    // The very same name, letter for letter, before names that only read alike ("Powder Room" before "Bathroom").
                    $matches[$key][$section->id] = $score + (self::plainName((string) $names[$key]) === self::plainName((string) $section->name) ? 1.0 : 0.0);
                }
            }
        }

        if ($matches === []) {
            return [];
        }

        $sections = EstimateSection::query()
            ->whereIn('id', collect($matches)->flatMap(fn (array $scores) => array_keys($scores))->unique()->values())
            ->with(['estimate.project', 'estimate_line_items' => fn ($q) => $q->orderBy('order')])
            ->get()
            ->keyBy('id');

        $likeThisJob = $this->semanticScores($vendorId, $inquiry, $sections->values()) ?? $this->lexicalScores($inquiry, $sections->values());

        $found = [];
        foreach ($matches as $key => $scores) {
            $found[$key] = collect($scores)
                ->filter(fn (float $nameScore, int $id) => $sections->has($id))
                ->map(fn (float $nameScore, int $id) => ['section' => $sections->get($id), 'name_score' => $nameScore, 'score' => round((float) ($likeThisJob[$id] ?? 0.0), 4)])
                ->sortBy([['name_score', 'desc'], ['score', 'desc'], [fn (array $a, array $b) => $b['section']->id <=> $a['section']->id]])
                ->take($perName)
                ->values()
                ->all();
        }

        return $found;
    }

    /**
     * The words of a section name that say which room or trade it is, as
     * tokens() reads them ("Laundry Room (2)" → mud), less the words that
     * name no room in particular.
     *
     * @return list<string>
     */
    public static function nameTokens(string $name): array
    {
        return array_values(array_diff(self::tokens(self::plainName($name)), self::GENERIC_NAME_WORDS));
    }

    /**
     * A section name as the estimator means it: without the layout a scan
     * put in front ("Floor 2 · Bedroom") or the number it put behind
     * ("Living Room (3)"), lower-cased.
     */
    public static function plainName(string $name): string
    {
        $name = Str::contains($name, ' · ') ? Str::afterLast($name, ' · ') : $name;

        return Str::lower(trim((string) preg_replace('/\s*\(\d+\)\s*$/', '', trim($name))));
    }

    /**
     * How alike two section names are, 0 when not alike: they must name the
     * same rooms (ROOM_WORDS) and share at least half of all their words —
     * shared words over all the words of both.
     *
     * @param  list<string>  $wanted
     * @param  list<string>  $candidate
     */
    public static function nameMatch(array $wanted, array $candidate): float
    {
        $shared = count(array_intersect($wanted, $candidate));

        if ($shared === 0) {
            return 0.0;
        }

        $rooms = fn (array $words) => array_values(array_intersect(self::ROOM_WORDS, $words));

        if ($rooms($wanted) !== $rooms($candidate)) {
            return 0.0;
        }

        $alike = $shared / count(array_unique([...$wanted, ...$candidate]));

        return $alike >= 0.5 ? round($alike, 4) : 0.0;
    }

    /**
     * What a section is "about", for embedding and for word matching.
     */
    public static function documentFor(EstimateSection $section): string
    {
        $parts = [Str::of((string) $section->name)->trim()->toString().'.'];

        if ($project = $section->estimate?->project?->project_name) {
            $parts[] = "Project: {$project}.";
        }

        // What was asked for this section; a section drafted as part of a
        // whole estimate keeps how its own scope was read instead (the
        // enquiry there covers every room of the job).
        $scope = filled($section->ai_inquiry) ? $section->ai_inquiry : $section->ai_scope;
        if (filled($scope)) {
            $parts[] = 'Scope: '.Str::limit(trim((string) $scope), 600, '').'.';
        }

        $items = $section->estimate_line_items->pluck('name')->filter()->unique()->values();
        if ($items->isNotEmpty()) {
            $parts[] = 'Items: '.$items->implode(', ').'.';
        }

        return implode(' ', $parts);
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower($text)) ?: [];
        $tokens = [];

        foreach ($words as $word) {
            if (strlen($word) < 3 || in_array($word, self::STOPWORDS, true) || is_numeric($word)) {
                continue;
            }

            $word = self::SYNONYMS[$word] ?? $word;
            $word = self::stem($word);
            $tokens[] = self::SYNONYMS[$word] ?? $word;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  list<int>  $excludeSectionIds
     * @return Collection<int, EstimateSection>
     */
    protected function candidates(int $vendorId, array $excludeSectionIds): Collection
    {
        return EstimateSection::query()
            ->whereHas('estimate', fn ($q) => $q->withoutGlobalScopes()->whereNull('deleted_at')->where('belongs_to_vendor_id', $vendorId))
            ->whereHas('estimate_line_items')
            ->where('name', '!=', '')
            ->where('name', 'not like', '%change order%')
            ->when($excludeSectionIds !== [], fn ($q) => $q->whereNotIn('id', $excludeSectionIds))
            ->with(['estimate.project', 'estimate_line_items' => fn ($q) => $q->orderBy('order')])
            ->latest('id')
            ->limit(self::CANDIDATES)
            ->get();
    }

    /**
     * @param  Collection<int, EstimateSection>  $candidates
     * @return array<int, float>|null section id → similarity, or null when embeddings are unavailable
     */
    protected function semanticScores(int $vendorId, string $inquiry, Collection $candidates): ?array
    {
        if (! $this->embeddings->enabled()) {
            return null;
        }

        $this->ensureEmbedded($vendorId, $candidates);

        $query = $this->embeddings->embed([$inquiry]);
        if ($query === null) {
            return null;
        }

        $vectors = EstimateSectionEmbedding::query()
            ->whereIn('section_id', $candidates->pluck('id'))
            ->where('model', $this->embeddings->model())
            ->get()
            ->keyBy('section_id');

        if ($vectors->isEmpty()) {
            return null;
        }

        $scores = [];
        foreach ($candidates as $section) {
            $vector = $vectors->get($section->id)?->vector;
            $scores[$section->id] = $vector ? EmbeddingService::cosine($query[0], $vector) : 0.0;
        }

        return $scores;
    }

    /**
     * Embed the candidates that have no vector yet, or whose text has changed
     * since they were embedded.
     *
     * @param  Collection<int, EstimateSection>  $candidates
     */
    protected function ensureEmbedded(int $vendorId, Collection $candidates): void
    {
        $model = $this->embeddings->model();
        $existing = EstimateSectionEmbedding::query()->whereIn('section_id', $candidates->pluck('id'))->get()->keyBy('section_id');

        $stale = $candidates->filter(function (EstimateSection $section) use ($existing, $model) {
            $row = $existing->get($section->id);

            return $row === null || $row->model !== $model || $row->text_hash !== hash('sha256', self::documentFor($section));
        })->values();

        foreach ($stale->chunk(self::BATCH) as $chunk) {
            $documents = $chunk->map(fn (EstimateSection $section) => self::documentFor($section))->values()->all();
            $vectors = $this->embeddings->embed($documents);

            if ($vectors === null) {
                return;
            }

            foreach ($chunk->values() as $i => $section) {
                EstimateSectionEmbedding::query()->updateOrCreate(
                    ['section_id' => $section->id],
                    ['vendor_id' => $vendorId, 'model' => $model, 'text_hash' => hash('sha256', $documents[$i]), 'vector' => $vectors[$i]],
                );
            }
        }
    }

    /**
     * Word overlap between the enquiry and each section, weighted towards
     * sections whose name shares a word with the enquiry.
     *
     * @param  Collection<int, EstimateSection>  $candidates
     * @return array<int, float>
     */
    protected function lexicalScores(string $inquiry, Collection $candidates): array
    {
        $query = self::tokens($inquiry);
        if ($query === []) {
            return [];
        }

        $scores = [];
        foreach ($candidates as $section) {
            $nameTokens = self::tokens((string) $section->name);
            $docTokens = self::tokens(self::documentFor($section));

            $nameHits = count(array_intersect($query, $nameTokens));
            $docHits = count(array_intersect($query, $docTokens));

            $score = $docHits === 0 ? 0.0 : $docHits / sqrt(max(count($docTokens), 1)) + $nameHits * 2.0;
            $scores[$section->id] = $score;
        }

        return $scores;
    }

    /** Just enough stemming to make "vanities" meet "vanity". */
    protected static function stem(string $word): string
    {
        foreach (['ies' => 'y', 'ing' => '', 'ers' => 'er', 'es' => '', 's' => ''] as $suffix => $replacement) {
            if (strlen($word) > strlen($suffix) + 3 && str_ends_with($word, $suffix)) {
                return substr($word, 0, -strlen($suffix)).$replacement;
            }
        }

        return $word;
    }
}
