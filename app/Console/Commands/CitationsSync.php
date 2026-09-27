<?php

namespace App\Console\Commands;

use App\Models\Citation;
use App\Support\Citations\KnownListings;
use Illuminate\Console\Command;

/**
 * Adapted from gsc's/dawnsellshomes' app/Console/Commands/CitationsSync.php.
 * Bring the citations table in line with config/citations.php: one row
 * per directory, new ones planned, existing statuses untouched.
 *
 * The source version also reconciles rows left "running" by a session
 * that ended without anyone polling, and bot-wall/busy-slot failure
 * notes, back onto the board — neither can ever happen here, since the
 * kit's Citations\UnavailableSession::start() always refuses before a
 * row is ever marked running (see that class's docblock), so that
 * cleanup is not ported.
 *
 *   php artisan citations:sync
 *   php artisan citations:sync --list
 */
class CitationsSync extends Command
{
    protected $signature = 'citations:sync {--list : Show every directory and its status}';

    protected $description = 'Register the configured directories in the citations table and list their status';

    public function handle(): int
    {
        $created = 0;

        foreach ((array) config('citations.directories', []) as $slug => $def) {
            $row = Citation::query()->where('slug', $slug)->first();
            $attrs = [
                'name' => $def['name'] ?? $slug, 'tier' => (int) ($def['tier'] ?? 1), 'mechanism' => (string) ($def['mechanism'] ?? 'form'),
                'homepage' => $def['homepage'] ?? null, 'start_url' => $def['start_url'] ?? ($def['homepage'] ?? null),
            ];
            if ($row) {
                $row->fill($attrs)->save();
            } else {
                Citation::create($attrs + ['slug' => $slug, 'status' => Citation::STATUS_PLANNED, 'note' => $def['note'] ?? null]);
                $created++;
            }
        }

        // Listings Social Media already knows (see KnownListings) read as
        // live here rather than as work to do.
        $matched = KnownListings::reconcile();

        $this->info("Citations registry synced ({$created} new, {$matched} matched).");

        if ($this->option('list')) {
            $rows = Citation::query()->orderBy('tier')->orderBy('name')->get();
            $this->table(['Tier', 'Directory', 'Status', 'Listing', 'Links to us', 'Human step / note'], $rows->map(fn ($c) => [
                $c->tier, $c->name, $c->status, $c->listing_url ? mb_substr($c->listing_url, 0, 50) : '—',
                $c->links_to_us === null ? '?' : ($c->links_to_us ? 'yes' : 'no'), mb_substr((string) ($c->human_reason ?: $c->note), 0, 60),
            ])->all());
        }

        return self::SUCCESS;
    }
}
