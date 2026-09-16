<?php

namespace App\Services\EstimateAI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Text to vectors, through the OpenAI embeddings endpoint the app already
 * has a key for. Without a key nothing is embedded and retrieval falls back
 * to word overlap.
 */
class EmbeddingService
{
    public function enabled(): bool
    {
        return filled(config('services.openai.api_key'));
    }

    public function model(): string
    {
        return (string) config('services.openai.embedding_model', 'text-embedding-3-small');
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>|null one vector per text, in order; null when unavailable
     */
    public function embed(array $texts): ?array
    {
        $texts = array_values(array_map(fn ($text) => mb_substr((string) $text, 0, 8000), $texts));

        if (! $this->enabled() || $texts === []) {
            return null;
        }

        try {
            $response = Http::withToken((string) config('services.openai.api_key'))
                ->timeout(30)
                ->post('https://api.openai.com/v1/embeddings', [
                    'model' => $this->model(),
                    'input' => $texts,
                ]);
        } catch (\Throwable $e) {
            Log::channel('estimate_ai')->warning('Embedding request failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful() || ! is_array($response->json('data'))) {
            Log::channel('estimate_ai')->warning('Embedding request rejected', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

            return null;
        }

        $rows = $response->json('data');
        usort($rows, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        $vectors = array_map(fn ($row) => array_map('floatval', $row['embedding'] ?? []), $rows);

        return count($vectors) === count($texts) ? array_values($vectors) : null;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $length = min(count($a), count($b));

        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
