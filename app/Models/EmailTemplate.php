<?php

namespace App\Models;

use App\Scopes\VendorScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_id',
        'name',
        'type',
        'subject',
        'body',
    ];

    protected static function booted()
    {
        static::addGlobalScope('vendor', function ($builder) {
            $user = auth()->user();
            
            if ($user && $user->vendor) {
                $vendorIds = $user->vendor->vendors->pluck('id')->push($user->vendor->id);
                $builder->whereIn('vendor_id', $vendorIds);
            }
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Replace placeholders in subject and body with actual values
     */
    public function render(array $data): array
    {
        $subject = $this->subject;
        $body = $this->body;

        foreach ($data as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            $subject = str_replace($placeholder, $value, $subject);
            $body = str_replace($placeholder, $value, $body);
        }

        return [
            'subject' => $subject,
            'body' => $body,
        ];
    }
}
