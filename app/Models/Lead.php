<?php

namespace App\Models;

use App\Scopes\LeadScope;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['date', 'origin', 'external_source', 'external_id', 'notes', 'user_id', 'lead_data', 'belongs_to_vendor_id', 'created_by_user_id', 'created_at', 'updated_at', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d H:i:s',
            'deleted_at' => 'date:Y-m-d',
            'lead_data' => AsArrayObject::class,
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new LeadScope);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(LeadStatus::class);
    }

    public function last_status(): HasOne
    {
        // id desc breaks created_at ties: two statuses written in the same
        // second (lead created then immediately converted, bulk actions) would
        // otherwise resolve to whichever row the driver returned first, showing
        // a stale status. Matches scopeWhereLatestStatus' ordering.
        return $this->hasOne(LeadStatus::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Canonical lead statuses with badge colors — single source of truth for
     * the row dropdown, bulk actions and the edit form. Shaped like
     * ProjectStatus::selectableStatuses() so both feed <x-status-select />.
     *
     * @return array<int, array{code: string, label: string, color: string}>
     */
    public static function selectableStatuses(): array
    {
        return collect([
            'New' => 'yellow',
            'Message 1' => 'zinc',
            'Message 2' => 'zinc',
            'Message 3' => 'zinc',
            'Won' => 'green',
            'Lost' => 'red',
            'Not a Fit' => 'red',
        ])
            ->map(fn (string $color, string $title) => [
                'code' => $title,
                'label' => $title,
                'color' => $color,
            ])
            ->values()
            ->all();
    }

    /**
     * Filter by the lead's CURRENT status. `whereHas('last_status')` can't do
     * this — it matches any row in the history (every lead has a "New" row).
     * Leads with no status rows yet count as "New".
     */
    public function scopeWhereLatestStatus($query, string|array $titles)
    {
        $titles = array_values((array) $titles);
        $placeholders = implode(',', array_fill(0, count($titles), '?'));

        return $query->whereRaw("
            COALESCE((
                select title from lead_statuses
                where lead_statuses.lead_id = leads.id
                order by created_at desc, id desc
                limit 1
            ), 'New') in ({$placeholders})
        ", $titles);
    }

    /**
     * The client this lead turned into, if any — single source of truth for
     * the leads table, the "Won" backfill command and the project-created job.
     *
     * Two signals: the lead's contact belongs to a client, or (for leads with
     * no user account) the lead address matches a client of the same vendor.
     * Runs unscoped so queue jobs and console commands resolve the same way a
     * logged-in request does.
     */
    public function resolveClient(): ?Client
    {
        $client = $this->user?->clients()->withoutGlobalScopes()->first();

        if ($client) {
            return $client;
        }

        $address = $this->lead_data['address'] ?? null;
        $vendorId = $this->belongs_to_vendor_id;

        if (! $address || ! $vendorId) {
            return null;
        }

        $street = trim(explode(',', (string) $address)[0] ?? '');

        if ($street === '') {
            return null;
        }

        return Client::withoutGlobalScopes()
            ->whereHas('vendors', fn ($q) => $q->where('vendors.id', $vendorId))
            ->where('address', 'like', $street.'%')
            ->first();
    }

    /**
     * Record a status change, skipping no-op writes so the history stays
     * meaningful. Returns true when a new status row was actually created.
     */
    public function setStatus(string $title): bool
    {
        if ($this->last_status?->title === $title) {
            return false;
        }

        $this->statuses()->create([
            'title' => $title,
            'belongs_to_vendor_id' => $this->belongs_to_vendor_id,
        ]);

        $this->unsetRelation('last_status');

        return true;
    }

    /**
     * Address split for the leads table: city + street only — no state, zip,
     * country or unit/suite line. Leads arrive from webhooks and hand-typed
     * forms, so the address is one free-text blob; the explicit `city` field
     * wins when present, otherwise it's parsed out of the address.
     *
     * @return array{city: string, street: string}
     */
    public function shortAddressParts(): array
    {
        $raw = trim((string) ($this->lead_data['address'] ?? ''));

        $segments = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            fn (string $segment): bool => $segment !== ''
        ));

        $street = $segments[0] ?? '';

        $isUnit = fn (string $s): bool => (bool) preg_match('/^(?:#|ste\.?|suite|apt\.?|apartment|unit|floor|fl\.?|bldg|building|rm\.?|room)\b/i', $s);
        $isCountry = fn (string $s): bool => (bool) preg_match('/^(?:usa|u\.?s\.?a?\.?|united states(?: of america)?)$/i', $s);
        // "IL", "IL 60047", "Illinois 60047", "60047"
        $isStateOrZip = fn (string $s): bool => (bool) preg_match('/^(?:[A-Za-z]{2}\.?|[A-Za-z]{4,})?\s*\d{5}(?:-\d{4})?$/', $s)
            || (bool) preg_match('/^[A-Za-z]{2}\.?$/', $s);

        $city = trim((string) ($this->lead_data['city'] ?? ''));

        if ($city === '') {
            foreach (array_slice($segments, 1) as $segment) {
                if ($isUnit($segment) || $isCountry($segment) || $isStateOrZip($segment)) {
                    continue;
                }

                $city = $segment;
                break;
            }
        }

        return ['city' => $city, 'street' => $street];
    }

    public static function statusColor(?string $title): string
    {
        return collect(self::selectableStatuses())->firstWhere('code', $title)['color'] ?? 'zinc';
    }
}
