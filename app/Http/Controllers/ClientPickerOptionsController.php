<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * The client list every client picker on the site filters in the browser
 * (resources/views/components/client-picker.blade.php, the same Alpine
 * component as the vendor picker in resources/js/vendor-picker.js).
 *
 * Why: the /projects filter rendered every client as a Flux listbox option,
 * in both its layouts — 335 clients made 866 KB of the page's 1 MB, and the
 * whole lot travelled again on every status-card click (2026-11-06). One
 * compact JSON document instead, fetched once per page.
 *
 * Scoped like the Client model (ClientScope: the company's clients, or a
 * homeowner's own), newest first the way the select was, cached briefly per
 * company and answered with an ETag so a browser that holds the list gets a
 * 304. The list's version changes whenever a visible client is added,
 * removed or edited.
 */
class ClientPickerOptionsController extends Controller
{
    public const CACHE_SECONDS = 600;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $fingerprint = $this->fingerprint();
        $scope = $user->vendor?->id ? 'v'.$user->vendor->id : 'u'.$user->id;
        $key = sprintf('client-picker:%s:%s', $scope, $fingerprint);
        $etag = '"'.md5($key).'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, max-age=300'];

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response()->noContent(304, $headers);
        }

        $clients = Cache::remember($key, self::CACHE_SECONDS, fn () => $this->clients());

        return response()->json(['version' => $fingerprint, 'items' => $clients], 200, $headers);
    }

    /** Changes whenever a client the user can see is added, removed or edited. */
    protected function fingerprint(): string
    {
        $row = Client::query()->selectRaw('COUNT(*) AS total, MAX(clients.updated_at) AS updated')->first();

        return md5(($row->total ?? 0).'|'.($row->updated ?? ''));
    }

    /**
     * The same fields the vendor picker's rows carry, so the one Alpine
     * component reads both: `name` is what the row shows, `legal` what it is
     * also found by (the business name, every person's full name).
     *
     * @return list<array{id: int, name: string, legal: string, city: ?string, state: ?string, type: ?string}>
     */
    protected function clients(): array
    {
        return Client::query()
            ->with('users')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Client $client) => [
                'id' => (int) $client->id,
                'name' => (string) $client->name,
                'legal' => trim(collect([$client->business_name])
                    ->merge($client->users->map(fn ($user) => trim($user->first_name.' '.$user->last_name)))
                    ->filter()
                    ->unique()
                    ->implode(' ')),
                'city' => null,
                'state' => null,
                'type' => null,
            ])
            ->values()
            ->all();
    }
}
