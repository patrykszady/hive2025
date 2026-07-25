<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of work ("Carpentry", "Plumbing", …), scoped per contractor tenant.
 * Referenced by vendors as their default trade, printed on sworn statements
 * and lien waiver affidavits, and intended for planner/task categorization.
 */
class WorkType extends Model
{
    protected $fillable = ['belongs_to_vendor_id', 'name'];

    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class, 'work_type_id');
    }

    /**
     * Find-or-create by name for a tenant, matching case-insensitively so
     * "carpentry" and "Carpentry" stay one record.
     */
    public static function resolve(int $tenantVendorId, string $name): ?self
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $existing = static::query()
            ->where('belongs_to_vendor_id', $tenantVendorId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        return $existing ?? static::create([
            'belongs_to_vendor_id' => $tenantVendorId,
            'name' => $name,
        ]);
    }
}
