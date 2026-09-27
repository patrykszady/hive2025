<?php

namespace App\Console\Commands\Seo;

use App\Support\SeoStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Reports\Report;
use SsSystems\Platform\Reports\ReportRegistry;
use SsSystems\Platform\Reports\ReportResult;

/**
 * Ported verbatim from dawnsellshomes' identical class — see
 * vendor/ss-systems/platform-kit's docs/REPORTS-PORTING.md, "How a site
 * wraps a report". The kit owns the algorithm (thresholds, markdown
 * wording, scoring); this class and its ten subclasses own turning a
 * ReportResult back into console output, a saved markdown file and an
 * exit code.
 *
 * Exit-code shape, the same for every report unless a subclass overrides
 * exitCode() (health-check and health both do):
 *   - STATUS_ERROR       -> print the error, FAILURE.
 *   - STATUS_UNAVAILABLE -> print the reason; FAILURE when a capability is
 *     missing, SUCCESS when the report simply found nothing. Never reached
 *     from the admin's "Run" button (SeoReportController::regenerate()
 *     checks App\Support\Seo\Reports\ReportCapabilities FIRST and never
 *     calls an unavailable report's command at all) but a report can still
 *     decide this for itself mid-run when the command is run directly.
 *   - STATUS_OK / STATUS_DEGRADED -> the summary line, a console table, any
 *     alert logging, the markdown file when --markdown was passed, then
 *     SUCCESS.
 */
abstract class KitReportCommand extends Command
{
    /** The registry key — also the markdown filename (reports/{key}.md) and ReportRegistry::REPORTS' own key. */
    abstract protected function reportKey(): string;

    /** @return class-string<Report> */
    abstract protected function reportClass(): string;

    public function handle(): int
    {
        /** @var Report $report */
        $report = $this->laravel->make($this->reportClass());
        $result = $report->generate($this->reportOptions());

        return $this->respond($result);
    }

    protected function respond(ReportResult $result): int
    {
        if ($result->status === ReportResult::STATUS_ERROR) {
            $this->error($result->error ?? $result->summary);

            return self::FAILURE;
        }

        if ($result->status === ReportResult::STATUS_UNAVAILABLE) {
            $this->warn($result->summary);

            return $result->missing === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->line($result->summary);
        $this->renderTable($result->data);
        $this->logAlerts($result->data);
        $this->maybeSaveMarkdown($result->markdown);

        return $this->exitCode($result);
    }

    /**
     * Every artisan --flag this report's ReportRegistry entry documents,
     * dashes -> underscores, read straight off this command's own CLI
     * options. A flag never passed on the CLI (null, or false for a boolean
     * flag) is left out of $options entirely — the kit report's own
     * `?? default` fallback applies.
     *
     * @return array<string, mixed>
     */
    protected function reportOptions(): array
    {
        $options = [];

        foreach (array_keys(ReportRegistry::get($this->reportKey())['options']) as $key) {
            $flag = str_replace('_', '-', $key);
            if (! $this->hasOption($flag)) {
                continue;
            }

            $value = $this->option($flag);
            if ($value === null || $value === false) {
                continue;
            }

            $options[$key] = $value;
        }

        return $options;
    }

    /** SUCCESS for both ok and degraded, unless a subclass's original exit rule cared about the distinction (health-check, health). */
    protected function exitCode(ReportResult $result): int
    {
        return self::SUCCESS;
    }

    /** Nothing extra beyond the summary line, unless a subclass decides to print a console table. */
    protected function renderTable(array $data): void
    {
        //
    }

    /** No alert shape for this report, unless a subclass decides (and logs) something about its own findings. */
    protected function logAlerts(array $data): void
    {
        //
    }

    protected function maybeSaveMarkdown(string $markdown): void
    {
        if (! $this->hasOption('markdown') || ! $this->option('markdown')) {
            return;
        }

        $path = SeoStorage::path("reports/{$this->reportKey()}.md");
        Storage::disk('local')->put($path, $markdown);
        $this->info("Saved: {$path}");
    }

    /**
     * The three-key data['alert'] shape ContentDecayReport, CwvTemplateReport
     * and SchemaAuditReport all produce.
     *
     * @param  array<string, mixed>  $data
     */
    protected function logGenericAlert(array $data): void
    {
        $alert = $data['alert'] ?? null;
        if (! is_array($alert) || ($alert['suppressed'] ?? false) || ! ($alert['message'] ?? null)) {
            return;
        }

        if (($alert['level'] ?? null) === 'warning') {
            logger()->warning($alert['message'], $data);
        } elseif (($alert['level'] ?? null) === 'info') {
            logger()->info($alert['message'], $data);
        }
    }
}
