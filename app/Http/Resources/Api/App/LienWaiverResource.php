<?php

namespace App\Http\Resources\Api\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * A lien waiver as the app's Lien Waivers tab shows it — same statuses,
 * types and fields as App\Livewire\LienWaivers\Index/Show. With
 * withDetail() it adds the fields the detail screen needs beyond the list
 * row (project/client, notes, PDF link).
 *
 * @mixin \App\Models\LienWaiver
 */
class LienWaiverResource extends JsonResource
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
        $project = $this->resource->project;
        $vendor = $this->resource->vendor;

        $row = [
            'id' => $this->id,
            'type' => [
                'value' => $this->type?->value,
                'label' => $this->typeLabel(),
                'short_label' => $this->type?->shortLabel(),
            ],
            'status' => [
                'value' => $this->status?->value,
                'label' => $this->statusLabel(),
                'color' => $this->status?->color(),
            ],
            'amount' => (float) $this->amount,
            'through_date' => $this->through_date?->toDateString(),
            'vendor' => $vendor ? ['id' => $vendor->id, 'name' => $vendor->business_name] : null,
            'is_sub_waiver' => $this->resource->isSubWaiver(),
            'project' => $project ? ['id' => $project->id, 'name' => $project->project_name, 'address' => $project->short_address] : null,
            'client' => $project?->client ? ['id' => $project->client->id, 'name' => $project->client->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'signed_at' => $this->signed_at?->toIso8601String(),
            'can_resend' => ! $this->resource->isSigned() && $this->status?->value !== \App\Enums\LienWaiverStatus::Cancelled->value,
            'can_cancel' => ! $this->resource->isSigned() && $this->status?->value !== \App\Enums\LienWaiverStatus::Cancelled->value,
            'can_delete' => ! $this->resource->isSigned(),
            'web_path' => '/lien-waivers/'.$this->id,
        ];

        if (! $this->detail) {
            return $row;
        }

        return $row + [
            'jurisdiction' => $this->jurisdiction,
            'exceptions_amount' => (float) $this->exceptions_amount,
            'check' => $this->resource->check ? ['id' => $this->resource->check->id, 'check_number' => $this->resource->check->check_number] : null,
            'payment' => $this->resource->payment ? ['id' => $this->resource->payment->id, 'amount' => (float) $this->resource->payment->amount] : null,
            'pdf_url' => URL::temporarySignedRoute('lien-waivers.download.signed', now()->addHours(24), ['lienWaiver' => $this->id]),
        ];
    }
}
