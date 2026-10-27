<?php

namespace App\Models;

use App\Support\Permits\BuildingCodes;
use Database\Factories\VillageCodesFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One town's adopted building codes, as its own website lists them
 * (App\Services\Permits\VillageCodeFinder). The permit cover letter's CODES
 * block comes from here; the hand-typed registry in
 * App\Support\Permits\BuildingCodes seeds the first rows and stands in
 * until a town has one.
 *
 * @property int $id
 * @property string $town
 * @property string $state
 * @property string $status
 * @property string $found_by
 * @property string|null $source_url
 * @property list<string>|null $codes
 * @property list<string>|null $previous_codes
 * @property string|null $note
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $checked_at
 * @property \Illuminate\Support\Carbon|null $changed_at
 */
class VillageCodes extends Model
{
    /** @use HasFactory<VillageCodesFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_FOUND = 'found';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_FAILED = 'failed';

    public const FOUND_BY_HAND = 'hand';

    public const FOUND_BY_AI = 'ai';

    protected $table = 'village_codes';

    protected $fillable = [
        'town', 'state', 'status', 'found_by', 'source_url', 'codes', 'previous_codes',
        'note', 'last_error', 'checked_at', 'changed_at',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => BuildingCodes::forget());
        static::deleted(fn () => BuildingCodes::forget());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'codes' => 'array',
            'previous_codes' => 'array',
            'checked_at' => 'datetime',
            'changed_at' => 'datetime',
        ];
    }

    /** The town however it was typed: "lake zurich", "Village of Lake Zurich". */
    public static function normalizeTown(?string $town): string
    {
        return Str::of((string) $town)->squish()->replaceMatches('/^(city|village|town) of /i', '')->trim()->title()->value();
    }

    /** @param  Builder<self>  $query */
    public function scopeNamed(Builder $query, string $town, string $state = 'IL'): void
    {
        $query->whereRaw('LOWER(town) = ?', [Str::lower(self::normalizeTown($town))])->where('state', $state);
    }

    public function isFound(): bool
    {
        return $this->status === self::STATUS_FOUND && ! empty($this->codes);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function sourceHost(): ?string
    {
        $host = $this->source_url ? parse_url($this->source_url, PHP_URL_HOST) : null;

        return $host ? preg_replace('/^www\./', '', $host) : null;
    }
}
