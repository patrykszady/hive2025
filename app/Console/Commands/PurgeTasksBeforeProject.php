<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PurgeTasksBeforeProject extends Command
{
    protected $signature = 'tasks:purge-before-project
        {--cutoff-project-id=349 : Project id used to derive cutoff date from project created_at when --cutoff-date is not provided}
        {--cutoff-date= : Hard delete tasks where created_at is before this date/time (Y-m-d or full datetime)}
        {--execute : Actually perform the hard delete (default is dry-run)}
        {--yes : Skip the confirmation prompt when --execute is provided}
    ';

    protected $description = 'Hard delete tasks created before a cutoff date (dry-run by default).';

    public function handle(): int
    {
        $cutoffProjectId = (int) $this->option('cutoff-project-id');
        $cutoffDateInput = $this->option('cutoff-date');
        $execute = (bool) $this->option('execute');
        $skipConfirm = (bool) $this->option('yes');

        $cutoffAt = null;

        if (is_string($cutoffDateInput) && trim($cutoffDateInput) !== '') {
            try {
                $cutoffAt = Carbon::parse($cutoffDateInput)->utc();
            } catch (\Throwable) {
                $this->error('Option --cutoff-date is invalid. Use a parseable date like 2025-01-01 or 2025-01-01 12:00:00.');

                return self::FAILURE;
            }
        } else {
            if ($cutoffProjectId <= 0) {
                $this->error('Option --cutoff-project-id must be a positive integer when --cutoff-date is not provided.');

                return self::FAILURE;
            }

            $project = Project::withoutGlobalScopes()->find($cutoffProjectId);

            if (! $project) {
                $this->error("Project {$cutoffProjectId} was not found. Provide --cutoff-date explicitly or a valid --cutoff-project-id.");

                return self::FAILURE;
            }

            if (! $project->created_at) {
                $this->error("Project {$cutoffProjectId} has no created_at value. Provide --cutoff-date explicitly.");

                return self::FAILURE;
            }

            $cutoffAt = $project->created_at->copy()->utc();
        }

        $cutoffIso = $cutoffAt->toDateTimeString();

        $baseQuery = Task::query()
            ->withTrashed()
            ->where('created_at', '<', $cutoffAt);

        $totalCount = (clone $baseQuery)->count();

        if ($totalCount === 0) {
            $this->info("No tasks found with created_at < {$cutoffIso} (UTC).");

            return self::SUCCESS;
        }

        $softDeletedCount = (clone $baseQuery)->onlyTrashed()->count();
        $activeCount = $totalCount - $softDeletedCount;

        $this->warn('This command performs hard deletes (force delete).');
        $this->line("Cutoff timestamp (UTC): {$cutoffIso}");
        if (! (is_string($cutoffDateInput) && trim($cutoffDateInput) !== '')) {
            $this->line("Derived from project id: {$cutoffProjectId}");
        }
        $this->line("Matching tasks: {$totalCount}");
        $this->line("- Active tasks: {$activeCount}");
        $this->line("- Already soft-deleted tasks: {$softDeletedCount}");

        if (! $execute) {
            $this->info('Dry run complete. Re-run with --execute to perform deletion.');

            return self::SUCCESS;
        }

        if (! $skipConfirm && ! $this->confirm("Hard delete {$totalCount} tasks with created_at < {$cutoffIso} (UTC)?")) {
            $this->info('Aborted. No tasks were deleted.');

            return self::SUCCESS;
        }

        $deletedCount = (clone $baseQuery)->forceDelete();

        $this->info("Hard deleted {$deletedCount} tasks.");

        return self::SUCCESS;
    }
}
