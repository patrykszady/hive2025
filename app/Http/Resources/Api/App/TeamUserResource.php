<?php

namespace App\Http\Resources\Api\App;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A team member or client person as the app's Team tab and user detail
 * screen show them — see App\Livewire\Users\{UsersIndex,UserShow,UserDetails}.
 * With withDetail() it adds everything the detail/profile screen needs
 * beyond the team list row.
 *
 * @mixin \App\Models\User
 */
class TeamUserResource extends JsonResource
{
    protected bool $detail = false;

    public function withDetail(): static
    {
        $this->detail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actingUser = $request->user();
        $isEmployed = $this->resource->isEmployed();

        $row = [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'nickname' => $this->nickname,
            'email' => $this->email,
            'cell_phone' => $this->cell_phone,
            'is_employed' => $isEmployed,
            'role' => $isEmployed ? $this->resource->getRoleForVendor($actingUser->vendor->id) : null,
            'web_path' => '/users/'.$this->id,
        ];

        if (! $this->detail) {
            return $row;
        }

        $canEdit = $actingUser->can('update', $this->resource);
        $vendorPivot = $isEmployed
            ? $this->resource->vendors()->where('vendors.id', $actingUser->vendor->id)->first()?->pivot
            : null;

        return $row + [
            'preferred_language' => $this->preferred_language ?: 'English',
            'position' => $vendorPivot?->position,
            'start_date' => $canEdit ? $vendorPivot?->start_date?->format('Y-m-d') : null,
            'hourly_rate' => $canEdit ? $vendorPivot?->hourly_rate : null,
            'via_vendor' => $this->resource->via_vendor ? [
                'id' => $this->resource->via_vendor->id,
                'name' => $this->resource->via_vendor->business_name,
                'business_type' => $this->resource->via_vendor->business_type,
            ] : null,
            'can_edit' => $canEdit,
            'can_view_finances' => $canEdit && $isEmployed,
            'clients' => $this->resource->clients->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => $client->name,
                'address' => $client->address,
                'city' => $client->city,
                'web_path' => '/clients/'.$client->id,
            ])->values()->all(),
        ];
    }
}
