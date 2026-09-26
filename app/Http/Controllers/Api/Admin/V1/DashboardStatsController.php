<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;

/**
 * Two generic stat tiles (see ss-systems/CLAUDE.md's dashboard convention:
 * "tiles show trends, not lone numbers" — value + note + delta_pct vs the
 * prior same-length window, {key,label,value,note,delta_pct,href}).
 *
 * `signups` counts Vendor rows — a subcontractor business registering
 * itself (App\Livewire\Entry\VendorRegistration, off the marketing site's
 * own "Get started" CTA) is the actual "new company" event this app has;
 * there is no separate companies/accounts table. `users` counts User rows
 * the same way.
 *
 * Deliberately NOT App\Models\Lead: those belong to the GENERAL
 * CONTRACTORS' customers (their own CRM pipeline, read by gs.construction
 * over routes/api.php's existing /api/v1/leads), not to Hive's own
 * signups — ss-systems/CLAUDE.md is explicit that a site's own tenants'
 * customer data must never surface on the shared admin.
 *
 * There is no marketing-site contact/demo-request form to report either:
 * the welcome pages' only calls to action are route('registration') and
 * route('login') (resources/views/welcome.blade.php) — no submission is
 * ever stored, so that tile is omitted rather than sent as an always-zero
 * stub.
 *
 * Vendor::query()/User::query() run here with no authenticated user (this
 * whole surface is a stateless, session-less bearer-token API), so
 * Vendor's own VendorScope/ClientScope global scopes take their
 * auth()->guest() branch and apply no restriction — an unscoped, whole-
 * table count, not one tenant's slice of it.
 */
class DashboardStatsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'tiles' => [
                    $this->signupsTile(),
                    $this->usersTile(),
                ],
            ],
        ]);
    }

    protected function signupsTile(): array
    {
        $now = now();

        $current = Vendor::query()->where('created_at', '>=', (clone $now)->subDays(7))->count();
        $prior = Vendor::query()
            ->where('created_at', '>=', (clone $now)->subDays(14))
            ->where('created_at', '<', (clone $now)->subDays(7))
            ->count();
        $total = Vendor::query()->count();

        return [
            'key' => 'signups',
            'label' => 'New companies (7 days)',
            'value' => $current,
            'note' => number_format($total).' total',
            'delta_pct' => $this->deltaPct($current, $prior),
            'href' => null,
        ];
    }

    protected function usersTile(): array
    {
        $now = now();

        $current = User::query()->where('created_at', '>=', (clone $now)->subDays(7))->count();
        $prior = User::query()
            ->where('created_at', '>=', (clone $now)->subDays(14))
            ->where('created_at', '<', (clone $now)->subDays(7))
            ->count();
        $total = User::query()->count();

        return [
            'key' => 'users',
            'label' => 'New users (7 days)',
            'value' => $current,
            'note' => number_format($total).' total',
            'delta_pct' => $this->deltaPct($current, $prior),
            'href' => null,
        ];
    }

    /** vs the prior same-length window; null when there's nothing to compare. */
    protected function deltaPct(int|float $current, int|float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
