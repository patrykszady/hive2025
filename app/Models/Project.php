<?php

namespace App\Models;

use App\Models\Client;
use App\Models\ProjectStatus;
use App\Models\ProjectVendor;
use App\Scopes\ProjectScope;
use App\Traits\HasAddress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Facades\Auth;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\Searchable;

class Project extends Model
{
    use HasFactory, HasAddress, Searchable, SoftDeletes;

    /**
     * A compact, UI-friendly street address.
     *
     * Examples:
     * - "917 E Marion St" => "917 Marion"
     * - "4100 N Kennicott Ave" => "4100 Kennicott"
     */
    protected function shortAddress(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                return self::simplifyStreetAddress($attributes['address'] ?? null);
            }
        );
    }

    private static function simplifyStreetAddress(?string $address): ?string
    {
        if (!$address) {
            return null;
        }

        $address = trim((string) $address);
        $address = preg_replace('/\s+/', ' ', $address) ?? $address;

        // If anything includes a comma, keep only the street portion.
        $streetOnly = trim(explode(',', $address, 2)[0]);

        $tokens = preg_split('/\s+/', $streetOnly) ?: [];
        if (count($tokens) < 2) {
            return $streetOnly;
        }

        $first = array_shift($tokens);

        // Drop a directional token directly after the street number.
        if (!empty($tokens) && preg_match('/^(N|S|E|W|NE|NW|SE|SW)\.?$/i', (string) $tokens[0])) {
            array_shift($tokens);
        }

        $suffixes = [
            'ST', 'STREET',
            'AVE', 'AVENUE',
            'RD', 'ROAD',
            'DR', 'DRIVE',
            'CT', 'COURT',
            'LN', 'LANE',
            'BLVD', 'BOULEVARD',
            'PL', 'PLACE',
            'TER', 'TERRACE',
            'CIR', 'CIRCLE',
            'PKWY', 'PARKWAY',
            'WAY',
        ];

        while (!empty($tokens)) {
            $last = (string) end($tokens);
            $normalized = strtoupper(rtrim($last, '.'));

            if (!in_array($normalized, $suffixes, true)) {
                break;
            }

            array_pop($tokens);
        }

        $rest = trim(implode(' ', $tokens));

        return trim($first.' '.$rest);
    }

    protected $fillable = ['project_name', 'client_id', 'belongs_to_vendor_id', 'created_by_user_id', 'note', 'timesheet_id', 'created_by_user_id', 'note', 'do_not_include', 'address', 'address_2', 'city', 'state', 'zip_code', 'created_at', 'updated_at'];

    protected static function booted()
    {
        static::addGlobalScope(new ProjectScope);
    }

    /**
     * Get or create a schedule token for public client schedule access.
     */
    public function getOrCreateScheduleToken(): string
    {
        if (! empty($this->schedule_token)) {
            return $this->schedule_token;
        }

        // 8 bytes = 16 hex chars - short enough for SMS but still unique
        $token = bin2hex(random_bytes(8));
        $this->forceFill(['schedule_token' => $token])->saveQuietly();

        return $token;
    }

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return app()->environment('local') ? 'projects_index_dev' : 'projects_index';
    }

    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray(): array
    {
        // Get latest status with all needed data
        $latestStatus = null;
        $latestStatusCode = null;
        $latestStatusDate = null;
        
        if ($this->relationLoaded('latestStatus')) {
            $latestStatus = $this->latestStatus;
        } else {
            $latestStatus = $this->latestStatus;
        }
        
        if ($latestStatus) {
            $latestStatusCode = $latestStatus->status_code;
            $latestStatusDate = $latestStatus->start_date?->timestamp ?? 0;
        }
        
        $client = $this->relationLoaded('client')
            ? $this->client
            : $this->client()->with('users:id,first_name,last_name')->first();

        $clientUsers = $client
            ? ($client->relationLoaded('users') ? $client->users : $client->users()->select('first_name', 'last_name')->get())
            : collect();

        $clientId = $client?->id;
        $clientBusinessName = $client?->business_name;
        $clientFirstNames = $client?->first_names;
        $clientLastNames = $client?->last_names;
        $clientName = $client?->name;
        $clientUserFirstNames = $clientUsers->pluck('first_name')->filter()->values()->all();
        $clientUserLastNames = $clientUsers->pluck('last_name')->filter()->values()->all();
        $clientUserFullNames = $clientUsers
            ->map(fn ($user) => trim((string) ($user->first_name ?? '').' '.(string) ($user->last_name ?? '')))
            ->filter()
            ->values()
            ->all();
        $clientSearch = trim(implode(' ', array_filter(array_merge(
            [$clientName, $clientBusinessName, $clientFirstNames, $clientLastNames],
            $clientUserFullNames,
            $clientUserFirstNames,
            $clientUserLastNames
        ))));

        $vendorBusinessName = $this->relationLoaded('createdByVendor')
            ? $this->createdByVendor?->business_name
            : $this->createdByVendor()->value('business_name');

        return [
            'id' => (int) $this->id,
            'project_name' => $this->project_name,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zip_code,
            'client_id' => $clientId ? (int) $clientId : null,
            'client_business_name' => $clientBusinessName,
            'client_first_names' => $clientFirstNames,
            'client_last_names' => $clientLastNames,
            'client_name' => $clientName,
            'client_search' => $clientSearch,
            'client_user_first_names' => $clientUserFirstNames,
            'client_user_last_names' => $clientUserLastNames,
            'client_user_full_names' => $clientUserFullNames,
            'belongs_to_vendor_id' => (int) $this->belongs_to_vendor_id,
            'vendor_business_name' => $vendorBusinessName,
            'latest_status_code' => $latestStatusCode,
            'latest_status_date' => $latestStatusDate,
            'created_at' => $this->created_at?->timestamp ?? 0,
        ];
    }

    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with([
            'client.users:id,first_name,last_name',
            'createdByVendor:id,business_name',
            'latestStatus:project_status.id,project_status.project_id,project_status.status_code,project_status.start_date',
        ]);
    }

    /**
     * Create a search builder that respects user access permissions.
     */
    public static function scopedSearch(
        string $query = '',
        array $filterConditions = [],
        string $sortBy = 'latest_status_date',
        string $sortDirection = 'desc',
        ?User $user = null
    ): ScoutBuilder {
        $user ??= Auth::user();

        if (! $user) {
            throw new \RuntimeException('Project::scopedSearch() requires an authenticated user.');
        }

        $filters = [
            '__soft_deleted = 0',
        ];

        $belongsToVendorId = (int) $user->vendor->id;
        $filters[] = 'belongs_to_vendor_id = '.$belongsToVendorId;

        if ($user->vendor_role === 'Member' && isset($user->vendor_pivot->start_date)) {
            $projectsStartDate = \Carbon\Carbon::parse($user->vendor_pivot->start_date)
                ->subMonths(6)
                ->startOfDay()
                ->timestamp;
            $filters[] = 'created_at > '.$projectsStartDate;
        }

        foreach ($filterConditions as $condition) {
            if (is_string($condition) && $condition !== '') {
                $filters[] = $condition;
            }
        }

        $filterString = implode(' AND ', $filters);

        return self::search($query, function ($meilisearch, $searchQuery, $options) use ($filterString, $sortBy, $sortDirection) {
            $options['filter'] = $filterString;
            $options['sort'] = ["{$sortBy}:{$sortDirection}"];
            $options['matchingStrategy'] = 'all';

            return $meilisearch->search($searchQuery, $options);
        });
    }

    public function distributions(): BelongsToMany
    {
        return $this->belongsToMany(Distribution::class)->withPivot('percent', 'amount', 'created_at')->withTimestamps();
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function expenseSplits(): HasMany
    {
        return $this->hasMany(ExpenseSplits::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class)->withTimestamps();
    }

    public function createdByVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'belongs_to_vendor_id');
    }
    // //projects many to many vendors
    // public function vendors(): BelongsToMany
    // {
    //     return $this->belongsToMany(Vendor::class)->withPivot('client_id')->withTimestamps();
    // }

    // public function vendor(): belongsToMany
    // {
    //     //project has one vendor via the project_vendor pivot table
    //     // return $this->belongsTo(Vendor::class);
    //     return $this->belongsToMany(Vendor::class)->withPivot('client_id')->withTimestamps();
    // }

    // public function getVendorAttribute()
    // {
    //     return $this->vendor()->first();
    // }

    /**
     * Get the client for the project through the project_vendor pivot table
     */
    public function client(): HasOneThrough
    {
        return $this->hasOneThrough(
            Client::class,
            ProjectVendor::class,
            'project_id', // Foreign key on project_vendor table
            'id',         // Foreign key on clients table
            'id',         // Local key on projects table
            'client_id'   // Local key on project_vendor table
        );
    }

    // public function clients(): BelongsToMany
    // {
    //     //through project_vendor->client_id
    //     return $this->belongsToMany(Client::class, 'project_vendor')->withPivot('vendor_id')->withTimestamps();
    // }

    // public function client(): belongsToMany
    // {
    //     //project has one client via the project_vendor pivot table client_id
    //     // return $this->hasOneThrough(Client::class, 'project_vendor_pivot', 'project_id', 'client_id');
    //     //->using(ProjectVendor::class)
    //     return $this->belongsToMany(Client::class, 'project_vendor')->withPivot('vendor_id')->withTimestamps();
    // }

    // public function getClientAttribute()
    // {
    //     return $this->client()->wherePivot('vendor_id', $this->vendor->id)->first();
    // }

    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class);
    }

    public function hours(): HasMany
    {
        return $this->hasMany(Hour::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(ProjectStatus::class);
    }

    public function latestStatus(): HasOne
    {
        return $this->hasOne(ProjectStatus::class)->latestOfMany('start_date'); // Automatically picks the latest
    }

    public function scopeStatus($query, $status)
    {
        return $query->whereHas('latestStatus', function ($q) use ($status) {
            $q->whereIn('status_code', $status); // Check if the latest status code is in the given array
        });
    }

    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->whereHas('latestStatus', function ($q) {
            $q->where('status_code', '!=', 10);
        });
    }

    public function scopeOrderByLatestStatusDateDesc(Builder $query): Builder
    {
        return $query->orderBy(
            ProjectStatus::select('start_date')
                ->whereColumn('project_id', 'projects.id')
                ->latest('start_date')
                ->take(1),
            'desc'
        );
    }

    /**
     * Handle project_name formatting and access
     */
    protected function projectName(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value,
            set: fn ($value) => $value ? ucwords(strtolower($value)) : null
        );
    }

    /**
     * Get the display name for the project
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                // Split placeholder
                if (isset($attributes['split']) && $attributes['split']) {
                    return 'SPLIT';
                }

                // Distribution synthetic naming
                if (isset($attributes['distribution']) && $attributes['distribution']) {
                    if (isset($attributes['distribution_name']) && ! empty($attributes['distribution_name'])) {
                        return $attributes['distribution_name'];
                    }
                    if (isset($attributes['project_name']) && ! empty($attributes['project_name'])) {
                        return $attributes['project_name'];
                    }
                }

                // If we lack a project_name entirely
                if (! isset($attributes['project_name']) || empty($attributes['project_name'])) {
                    return 'No Project';
                }

                // Normalize explicit NO PROJECT strings
                $specialNames = ['No Project', 'NO PROJECT'];
                if (in_array($attributes['project_name'], $specialNames, true)) {
                    return 'No Project';
                }

                // Standard project: include address prefix if present
                if (! empty($attributes['address'])) {
                    $shortAddress = self::simplifyStreetAddress($attributes['address']);

                    return $shortAddress . ' | ' . $attributes['project_name'];
                }

                return $attributes['project_name'];
            }
        );
    }

    /**
     * Get the calculated financial data for the project
     */
    protected function finances(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                $expenses_sum = \App\Models\Expense::query()
                    ->withoutGlobalScope(\App\Scopes\ExpenseScope::class)
                    ->where('project_id', $this->id)
                    ->where('reimbursment', 'Client')
                    ->sum('amount');

                $splits_sum = \App\Models\ExpenseSplits::query()
                    ->withoutGlobalScope(\App\Scopes\ExpenseSplitsScope::class)
                    ->where('project_id', $this->id)
                    ->where('reimbursment', 'Client')
                    ->sum('amount');

                $finances = [];
                $bid_estimate_total = (float) $this->bids()->where('type', 1)->sum('amount');
                
                // If no finalized bids exist, calculate from estimate sections
                if ($bid_estimate_total == 0) {
                    $unfinalized_estimate_total = $this->estimates()
                        ->with('estimate_sections')
                        ->get()
                        ->flatMap(function ($estimate) {
                            return $estimate->estimate_sections;
                        })
                        ->sum('total');
                    $finances['estimate'] = (float) $unfinalized_estimate_total;
                } else {
                    $finances['estimate'] = $bid_estimate_total;
                }
                
                $finances['change_orders'] = $this->bids()->where('type', '!=', 1)->sum('amount');
                $finances['total_bid'] = $finances['estimate'] + $finances['change_orders'];
                $finances['reimbursments'] = $splits_sum + $expenses_sum;
                $finances['total_project'] = round($finances['reimbursments'] + $finances['estimate'] + $finances['change_orders'], 2);
                $finances['expenses'] = $this->expenses->sum('amount') + $this->expenseSplits->sum('amount');
                $finances['timesheets'] = $this->timesheets->sum('amount');
                $finances['total_cost'] = $finances['timesheets'] + $finances['expenses'];
                $finances['payments'] = round($this->payments->sum('amount'), 2);
                $finances['profit'] = $finances['payments'] - $finances['total_cost'];
                $finances['balance'] = $finances['total_project'] - $finances['payments'];

                return $finances;
            }
        );
    }
}