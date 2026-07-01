<?php

namespace App\Models;

use App\Scopes\TimesheetScope;
use App\Traits\HasNumericSearch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\Searchable;

class Timesheet extends Model
{
    use HasFactory, HasNumericSearch, Searchable, SoftDeletes;

    protected $fillable = ['date', 'user_id', 'vendor_id', 'project_id', 'hours', 'amount', 'paid_by', 'check_id', 'hourly', 'invoice', 'note', 'created_by_user_id', 'created_at', 'updated_at', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }

    protected static function booted()
    {
        static::addGlobalScope(new TimesheetScope);
    }

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return app()->environment('local') ? 'timesheets_index_dev' : 'timesheets_index';
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $userName = User::withoutGlobalScopes()
            ->whereKey($this->user_id)
            ->value('first_name');

        $lastName = User::withoutGlobalScopes()
            ->whereKey($this->user_id)
            ->value('last_name');

        return [
            'id' => (string) $this->id,
            'date' => $this->date?->timestamp ?? 0,
            'user_id' => (int) $this->user_id,
            'vendor_id' => (int) $this->vendor_id,
            'project_id' => is_null($this->project_id) ? null : (int) $this->project_id,
            'check_id' => is_null($this->check_id) ? null : (int) $this->check_id,
            'paid_by' => is_null($this->paid_by) ? null : (int) $this->paid_by,
            'is_paid' => ! is_null($this->check_id),
            'amount' => (float) $this->amount,
            'hours' => (float) $this->hours,
            'user_name' => trim(($userName ?? '') . ' ' . ($lastName ?? '')),
            'note' => (string) $this->note,
            'invoice' => (string) $this->invoice,
        ];
    }

    /**
     * Create a search builder scoped to the authenticated user's vendor and role.
     *
     * @param  array<int, string>  $filterConditions
     */
    public static function scopedSearch(string $query = '', array $filterConditions = [], string $sortBy = 'date', string $sortDirection = 'desc', ?User $user = null): ScoutBuilder
    {
        $user ??= Auth::user();

        if (! $user || ! $user->vendor) {
            throw new \RuntimeException('Timesheet::scopedSearch() requires an authenticated user with a vendor.');
        }

        $vendorId = (int) $user->vendor->id;
        $baseFilter = "__soft_deleted = 0 AND vendor_id = {$vendorId}";

        if ($user->vendor_role === 'Member') {
            $baseFilter .= ' AND user_id = ' . (int) $user->id;
        }

        return self::search($query, function ($engine, $searchQuery = null, $options = null) use ($filterConditions, $sortBy, $sortDirection, $baseFilter) {
            // Scout's non-Meilisearch engines call the callback with a non-string
            // search query argument. Tenant isolation is already enforced by the
            // global TimesheetScope for those engines, so there is nothing
            // additional to apply.
            if (! is_string($searchQuery)) {
                return;
            }

            [$actualQuery, $augmentedFilters] = self::processNumericSearch($searchQuery, $filterConditions);

            $options = self::applySearchOptions($options, $baseFilter, $augmentedFilters, $actualQuery, $sortBy, $sortDirection);

            return $engine->search($actualQuery, $options);
        });
    }

    public function hours(): HasMany
    {
        return $this->hasMany(Hour::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
