<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\SoftDeletes;
use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;

/**
 * Bring every search index up to date WITHOUT deleting it: sync the index
 * settings, upsert every record (synchronously, model by model), then remove
 * the documents whose rows no longer exist.
 *
 * `scout:reindex --full` deletes all indexes and refills them through the
 * queue; until each one refills it knows no fields, and every amount search
 * on /expenses failed ("Attribute `amount` is not searchable") — on dev after
 * every refresh from production (2026-10-13). Here search keeps answering
 * throughout, from the old documents until the new ones land.
 *
 * Runs at the end of ~/web/pull-prod.sh on dev and, detached, after every
 * production deploy.
 */
class ScoutRefresh extends Command
{
    protected $signature = 'scout:refresh
        {--models=* : Only these models (class names); default every model in scout.meilisearch.index-settings}
        {--no-prune : Keep documents whose rows are gone}
        {--chunk=500 : Records per upsert batch}';

    protected $description = 'Refresh every search index in place: sync settings, upsert all records, prune deleted ones';

    public function handle(): int
    {
        $models = array_values(array_filter((array) $this->option('models'))) ?: array_keys(config('scout.meilisearch.index-settings', []));
        if ($models === []) {
            $this->error('No searchable models configured.');

            return self::FAILURE;
        }

        // Synchronous: the refresh is finished when the command is.
        config(['scout.queue' => false]);
        $this->call('scout:sync-index-settings');

        $meilisearch = config('scout.driver') === 'meilisearch';
        $failed = [];

        foreach ($models as $class) {
            $started = microtime(true);
            try {
                /** @var \Illuminate\Database\Eloquent\Model&\Laravel\Scout\Searchable $model */
                $model = new $class;
                $class::makeAllSearchable((int) $this->option('chunk'));
                $pruned = $meilisearch && ! $this->option('no-prune') ? $this->prune($model) : 0;
                $this->line(sprintf('  %s: refreshed%s (%ss)', class_basename($class), $pruned ? ", {$pruned} stale document(s) removed" : '', round(microtime(true) - $started, 1)));
            } catch (\Throwable $e) {
                $failed[] = class_basename($class);
                $this->error(sprintf('  %s: %s', class_basename($class), $e->getMessage()));
            }
        }

        if ($failed !== []) {
            $this->error('Search refresh finished with errors: '.implode(', ', $failed));

            return self::FAILURE;
        }
        $this->info('Search refresh complete.');

        return self::SUCCESS;
    }

    /** Remove the index's documents whose rows no longer exist; returns how many. */
    protected function prune($model): int
    {
        $key = method_exists($model, 'getScoutKeyName') ? $model->getScoutKeyName() : $model->getKeyName();
        $index = app(Client::class)->index(method_exists($model, 'indexableAs') ? $model->indexableAs() : $model->searchableAs());

        $indexIds = [];
        $offset = 0;
        do {
            $page = $index->getDocuments((new DocumentsQuery)->setFields([$key])->setLimit(1000)->setOffset($offset));
            foreach ($page->getResults() as $doc) {
                if (isset($doc[$key])) {
                    $indexIds[] = (string) $doc[$key];
                }
            }
            $offset += 1000;
        } while (count($page->getResults()) === 1000);

        // Every row, trashed ones included when Scout indexes them (soft_delete).
        $query = $model->newQuery()->withoutGlobalScopes();
        if (in_array(SoftDeletes::class, class_uses_recursive($model), true) && ! config('scout.soft_delete')) {
            $query->whereNull($model->getQualifiedDeletedAtColumn());
        }
        $dbIds = $query->pluck($model->qualifyColumn($model->getKeyName()))->map(fn ($id) => (string) $id)->all();

        $stale = self::staleIds($indexIds, $dbIds);
        foreach (array_chunk($stale, 1000) as $batch) {
            $index->deleteDocuments($batch);
        }

        return count($stale);
    }

    /**
     * Document ids the index holds that the table no longer has.
     *
     * @param  list<string>  $indexIds
     * @param  list<string>  $dbIds
     * @return list<string>
     */
    public static function staleIds(array $indexIds, array $dbIds): array
    {
        return array_values(array_diff($indexIds, $dbIds));
    }
}
