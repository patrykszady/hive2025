<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\Citation;
use App\Services\Citations\CitationSessionService;
use App\Support\Citations\KnownListings;
use App\Support\Citations\ListingPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Ported from gsc's/dawnsellshomes' Api/Admin/V1/CitationsController.php,
 * simplified for a host with no remote-browser pipeline at all — see
 * App\Services\Citations\CitationSessionService's docblock. Management
 * API for the citation board consumed by ss-systems'
 * App\Livewire\Admin\Citations (+ blade): the directory board, the
 * canonical listing payload (so the admin can copy any field by hand),
 * and the manual status/URL/note edit that is this app's real workflow.
 *
 * start/poll/resume/stop/batch all still exist — the client calls them
 * unconditionally — but every one answers honestly that there is no
 * browser session, rather than 404ing or pretending. No noVNC viewer
 * route is registered: nothing here ever produces a viewer URL to sign.
 */
class CitationsController extends Controller
{
    public function index(CitationSessionService $sessions): JsonResponse
    {
        $this->ensureSynced();
        KnownListings::reconcile();
        $rows = Citation::query()->orderBy('tier')->orderBy('name')->get();

        return response()->json(['data' => [
            'citations' => $rows->map(fn (Citation $c) => $this->row($c))->values()->all(),
            'counts' => $rows->countBy('status')->all(),
            'session' => $this->emptySession(),
            'inbox_configured' => false,
            'batch' => ['active' => false, 'remaining' => [], 'done' => 0, 'current' => null],
            'requirements' => $sessions->checkRequirements(),
        ]]);
    }

    public function payload(): JsonResponse
    {
        return response()->json(['data' => ListingPayload::make()]);
    }

    /** "Run all automatically" — always refused, with the reason, since there is no browser to run it in. */
    public function batch(CitationSessionService $sessions): JsonResponse
    {
        $this->ensureSynced();
        $req = $sessions->checkRequirements(true);

        return response()->json(['data' => [
            'ok' => false,
            'error' => 'Browser automation is not set up on this host (missing: '.implode(', ', $req['missing']).'). Build listings by hand from the payload above.',
        ]]);
    }

    public function start(string $slug, CitationSessionService $sessions): JsonResponse
    {
        $citation = $this->find($slug);
        $result = $sessions->start($citation);
        $citation->addLog((string) $result['error'], 'blocked');
        $citation->save();

        return response()->json(['data' => ['ok' => false, 'error' => $result['error'], 'citation' => $this->row($citation)]]);
    }

    public function poll(CitationSessionService $sessions): JsonResponse
    {
        return response()->json(['data' => [
            'session' => $this->emptySession(),
            'citation' => null,
            'log_tail' => $sessions->tailLog(),
        ]]);
    }

    public function resume(string $slug, CitationSessionService $sessions): JsonResponse
    {
        $citation = $this->find($slug);
        $sessions->resume($citation);

        return response()->json(['data' => ['ok' => false, 'error' => 'Browser automation is not set up on this host. Continue this one by hand.', 'citation' => $this->row($citation)]]);
    }

    public function stop(CitationSessionService $sessions): JsonResponse
    {
        $sessions->stop();

        return response()->json(['data' => ['ok' => true, 'citation' => null]]);
    }

    /** The manual edit form — the real way a citation moves on this app. */
    public function update(Request $request, string $slug): JsonResponse
    {
        $citation = $this->find($slug);
        $data = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', Citation::STATUSES)],
            'listing_url' => ['nullable', 'url', 'max:500'],
            'note' => ['nullable', 'string', 'max:2000'],
            'account_email' => ['nullable', 'email', 'max:191'],
        ]);
        foreach (['listing_url', 'note', 'account_email'] as $f) {
            if (array_key_exists($f, $data)) {
                $citation->{$f} = $data[$f];
            }
        }
        if (! empty($data['status'])) {
            $citation->status = $data['status'];
            if ($data['status'] === Citation::STATUS_LIVE) {
                $citation->live_at = $citation->live_at ?: now();
                $citation->human_reason = null;
            }
            $citation->addLog('Status set to '.$data['status'].' by the admin', 'manual');
        }
        $citation->save();

        return response()->json(['data' => ['ok' => true, 'citation' => $this->row($citation)]]);
    }

    public function screenshot(string $slug, string $file, CitationSessionService $sessions): BinaryFileResponse
    {
        $citation = $this->find($slug);
        abort_unless(preg_match('/^[a-z0-9._-]+\.(png|jpg)$/i', $file), 404);
        $path = $sessions->dirFor($citation).'/shots/'.$file;
        abort_unless(is_file($path), 404);

        return response()->file($path);
    }

    protected function row(Citation $c): array
    {
        $def = $c->definition();

        return [
            'slug' => $c->slug, 'name' => $c->name, 'tier' => $c->tier, 'mechanism' => $c->mechanism,
            'homepage' => $c->homepage, 'start_url' => $c->start_url, 'listing_url' => $c->listing_url, 'status' => $c->status,
            'account_email' => $c->account_email, 'has_password' => $c->account_password !== null,
            'photos_uploaded' => $c->photos_uploaded, 'links_to_us' => $c->links_to_us, 'nofollow' => $c->nofollow,
            'human_reason' => $c->human_reason, 'note' => $c->note, 'definition_note' => $def['note'] ?? null,
            'needs' => $def['needs'] ?? [], 'photos' => (bool) ($def['photos'] ?? false),
            'log' => array_slice((array) ($c->log ?? []), -12), 'screenshots' => (array) ($c->screenshots ?? []),
            'verification' => (array) ($c->verification ?? []),
            'last_run_at' => $c->last_run_at?->toIso8601String(), 'submitted_at' => $c->submitted_at?->toIso8601String(),
            'live_at' => $c->live_at?->toIso8601String(), 'last_checked_at' => $c->last_checked_at?->toIso8601String(),
        ];
    }

    protected function emptySession(): array
    {
        return ['running' => false, 'slug' => null, 'expires_at' => null, 'runner' => null, 'viewer_url' => null];
    }

    protected function find(string $slug): Citation
    {
        $this->ensureSynced();

        return Citation::query()->where('slug', $slug)->firstOrFail();
    }

    protected function ensureSynced(): void
    {
        $expected = count((array) config('citations.directories', []));
        if (Citation::query()->count() < $expected) {
            Artisan::call('citations:sync');
        }
    }
}
