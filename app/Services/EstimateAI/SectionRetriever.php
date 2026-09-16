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

    private const SYNONYMS = [
        'bathroom' => 'bath', 'baths' => 'bath', 'washroom' => 'bath', 'powder' => 'bath',
        'master' => 'primary', 'ensuite' => 'primary',
        'mudroom' => 'mud', 'laundry' => 'mud',
        'family' => 'living', 'lounge' => 'living', 'den' => 'living',
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
     * What a section is "about", for embedding and for word matching.
     */
    public static function documentFor(EstimateSection $section): string
    {
        $parts = [Str::of((string) $section->name)->trim()->toString().'.'];

        if ($project = $section->estimate?->project?->project_name) {
            $parts[] = "Project: {$project}.";
        }

        if (filled($section->ai_inquiry)) {
            $parts[] = 'Scope: '.Str::limit(trim((string) $section->ai_inquiry), 600, '').'.';
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
