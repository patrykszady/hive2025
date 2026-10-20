<?php

namespace App\Http\Resources\Api\App;

use App\Services\Users\AppSectionPermissions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'cell_phone' => $this->cell_phone,
            // The Manage hub's "Edit Company" row (vendor-details.blade.php's
            // own-company "Edit" button) needs this to push straight to
            // /vendors/{id}/edit — null for a person with no primary vendor.
            'vendor_id' => $this->resource->vendor?->id,
            'permissions' => AppSectionPermissions::for($this->resource),
        ];
    }
}
