<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Livewire\Planner\CardsIndex;
use App\Models\ProjectActionItem;
use App\Services\ActionItems\ProjectActionItemExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The action tasks a project's calls and texts add up to, read since the
 * last run (or from the start with --fresh) and listed grouped: one row per
 * task however many times it came up.
 */
class ProjectActionItems extends Command
{
    protected $signature = 'project:action-items
        {project? : Project id}
        {--active : Every project in a planner status instead of one id (the nightly run)}
        {--since= : Only calls and texts on or after this date (default: the project\'s creation)}
        {--fresh : Forget earlier runs for this project and read everything again}
        {--dry-run : Read and group, show the list, save nothing}
        {--review : Run the model review of the open list even when nothing new came in}
        {--all : Also list items already done, dismissed or turned into tasks}';

    protected $description = 'Collect and group the action tasks a project\'s phone calls, text messages and emails ask for or schedule.';

    public function handle(ProjectActionItemExtractor $extractor): int
    {
        if ($this->option('active')) {
            return $this->runForActiveProjects($extractor);
        }

        $project = Project::withoutGlobalScopes()->find((int) $this->argument('project'));
        if (! $project) {
            $this->error('No such project.');

            return self::FAILURE;
        }

        $since = $this->option('since')
            ? Carbon::parse((string) $this->option('since'))->startOfDay()
            : Carbon::parse($project->created_at)->startOfDay();
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf('Project #%d %s — calls and texts since %s%s', $project->id, $project->short_address, $since->toDateString(), $dryRun ? ' (dry run)' : ''));

        DB::beginTransaction();
        try {
            $stats = $extractor->run($project, $since, (bool) $this->option('fresh'), (bool) $this->option('review'));
            $this->line($this->summary($stats));
            $this->table(...$this->rows($project));
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        return self::SUCCESS;
    }

    /**
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    protected function rows(Project $project): array
    {
        $items = ProjectActionItem::query()
            ->where('project_id', $project->id)
            ->when(! $this->option('all'), fn ($query) => $query->open())
            ->orderByDesc('last_mentioned_at')
            ->get();

        $rows = $items->map(fn (ProjectActionItem $item) => [
            $item->status,
            (string) ($item->owner ?? ''),
            Str::limit($item->title, 70),
            $item->due_on?->toDateString() ?? '',
            (string) $item->mentions,
            $item->first_mentioned_at?->format('m-d') ?? '',
            $item->last_mentioned_at?->format('m-d') ?? '',
            collect($item->sources ?? [])->map(fn (array $s) => ($s['kind'] ?? '?').' '.($s['id'] ?? '?'))->unique()->take(4)->implode(', '),
        ])->all();

        return [['Status', 'Owner', 'Action', 'Due', '×', 'First', 'Last', 'Sources'], $rows];
    }

    /**
     * The nightly pass: every project the planner lists, each read since its
     * last run. One failing project does not stop the others.
     */
    protected function runForActiveProjects(ProjectActionItemExtractor $extractor): int
    {
        $projects = Project::withoutGlobalScopes()
            ->whereHas('latestStatus', fn ($query) => $query->whereIn('status_code', CardsIndex::PLANNER_PROJECT_STATUS_CODES))
            ->orderBy('id')
            ->get();

        $this->info(sprintf('%d active projects', $projects->count()));
        $failed = 0;

        foreach ($projects as $project) {
            try {
                $stats = $extractor->run($project, Carbon::parse($project->created_at)->startOfDay());
                $this->line(sprintf('  #%d %-32s %s', $project->id, Str::limit((string) $project->short_address, 32), $this->summary($stats)));
            } catch (\Throwable $e) {
                $failed++;
                $this->error(sprintf('  #%d %s: %s', $project->id, $project->short_address, $e->getMessage()));
                report($e);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{calls: int, messages: int, emails: int, created: int, merged: int, done: int, lapsed: int, consolidated: array{merged: int, done: int, dropped: int}}  $stats
     */
    protected function summary(array $stats): string
    {
        $c = $stats['consolidated'];

        return sprintf(
            'Read %d calls, %d messages and %d emails: %d new items, %d folded into existing ones, %d closed by texts, %d lapsed; review merged %d, closed %d, dropped %d.',
            $stats['calls'], $stats['messages'], $stats['emails'], $stats['created'], $stats['merged'], $stats['done'], $stats['lapsed'], $c['merged'], $c['done'], $c['dropped'],
        );
    }
}
