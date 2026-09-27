<?php

namespace App\Console\Commands;

use App\Models\PsiSnapshot;
use App\Services\PageSpeedInsightsService;
use App\Support\Seo\PsiSettings;
use Illuminate\Console\Command;

/**
 * Snapshot PageSpeed Insights for this site's key pages, so the kit's
 * CwvTemplateReport ('psi_snapshots' capability) has real rows to
 * aggregate — trimmed from gs.construction's identical seo:psi-sync (no
 * dynamic Search-Console-driven URL pool, no per-run retry cache: this
 * site's page inventory is small and fixed, unlike a multi-tenant listings
 * site's).
 *
 * No-ops cleanly (SUCCESS, a plain message) until a PageSpeed key is saved
 * from the SEO screen's Connect Services modal (App\Support\Seo\
 * PsiSettings::usingOwnKey()) — PSI itself never requires a key, but
 * running an automatic daily sync against Google's shared anonymous quota
 * would compete with real ad hoc checks, so this waits for an owner to
 * opt in with their own key. That is also what App\Support\Seo\Reports\
 * ReportCapabilities gates 'psi_snapshots' on, so the admin's cwv-template
 * report and this sync flip on together.
 */
class SeoPsiSync extends Command
{
    protected $signature = 'seo:psi-sync {--strategy=mobile : mobile, desktop, or both}';

    protected $description = 'Snapshot PageSpeed Insights (Lighthouse + CrUX) for this site\'s key pages.';

    public function handle(PageSpeedInsightsService $svc): int
    {
        if (! app(PsiSettings::class)->usingOwnKey()) {
            $this->info('No PageSpeed key saved — skipping (PSI sync waits for an owner-provided key; see class docblock).');

            return self::SUCCESS;
        }

        $strategies = match ($this->option('strategy')) {
            'desktop' => ['desktop'],
            'both' => ['mobile', 'desktop'],
            default => ['mobile'],
        };

        $urls = $this->urls();
        $today = now()->toDateString();
        $ok = 0;
        $failed = 0;

        foreach ($urls as $url) {
            foreach ($strategies as $strategy) {
                $result = $svc->run($url, $strategy);

                if ($result === null) {
                    $failed++;
                    $this->warn("PSI failed: {$strategy} {$url}");

                    continue;
                }

                PsiSnapshot::updateOrCreate(
                    ['date' => $today, 'url' => $url, 'strategy' => $strategy],
                    $result,
                );
                $ok++;
            }
        }

        $this->info("Done. ok={$ok} failed={$failed}");

        return self::SUCCESS;
    }

    /**
     * A small, fixed set of key pages — this app's marketing site has none
     * of gs.construction's per-city/per-service landing-page sprawl, so a
     * dynamic URL pool would be over-engineering. The welcome page in every
     * supported locale, plus this site's two most-linked feature pages.
     *
     * @return list<string>
     */
    protected function urls(): array
    {
        $base = rtrim((string) config('app.marketing_url', config('app.url')), '/');

        $urls = [];
        foreach (array_keys(config('locales.supported', ['en' => []])) as $locale) {
            $urls[] = "{$base}/{$locale}/welcome";
        }
        $urls[] = "{$base}/en/welcome/finances";
        $urls[] = "{$base}/en/welcome/homeowners";

        return array_values(array_unique($urls));
    }
}
