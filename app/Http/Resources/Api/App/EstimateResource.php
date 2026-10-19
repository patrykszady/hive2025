<?php

namespace App\Http\Resources\Api\App;

use App\Models\EstimateLineItem;
use App\Models\EstimateLineItemAllowance;
use App\Models\EstimateSection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Estimate
 */
class EstimateResource extends JsonResource
{
    protected bool $detail = false;

    public function withDetail(): static
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $project = $this->resource->project;
        $client = $project?->client;

        $row = [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'total' => $this->total(),
            'created_at' => $this->created_at?->toIso8601String(),
            'project' => $project ? ['id' => $project->id, 'name' => $project->project_name] : null,
            'client' => $client ? ['id' => $client->id, 'name' => $client->name] : null,
            'web_path' => '/estimates/'.$this->id,
        ];

        if (! $this->detail) {
            return $row;
        }

        return $row + [
            'sections' => $this->resource->estimate_sections
                ->sortBy('order')
                ->values()
                ->map(fn (EstimateSection $section) => $this->sectionArray($section))
                ->all(),
            'dates' => [
                'start_date' => $this->start_date?->toDateString(),
                'end_date' => $this->end_date?->toDateString(),
            ],
            'payments' => $this->payments,
            'reimbursements' => round((float) ($this->reimbursments ?? 0), 2),
            'signing' => [
                'is_vendor_signed' => $this->resource->isVendorSigned(),
                'is_fully_signed' => $this->resource->isFullySigned(),
                'is_signed' => $this->resource->isSigned(),
                'signed_contract_available' => $this->signed_contract_path !== null,
            ],
        ];
    }

    /**
     * The estimate's total — the sum of every section's total, computed the
     * same way App\Livewire\Estimates\EstimateShow's own total does (never a
     * stored/cached figure).
     */
    protected function total(): float
    {
        return round((float) $this->resource->estimate_sections->sum('total'), 2);
    }

    /**
     * @return array<string, mixed>
     */
    protected function sectionArray(EstimateSection $section): array
    {
        return [
            'id' => $section->id,
            'name' => $section->name,
            'order' => $section->order,
            'total' => round((float) $section->total, 2),
            'locked' => $section->isLocked(),
            'line_items' => $section->estimate_line_items
                ->sortBy('order')
                ->values()
                ->map(fn (EstimateLineItem $lineItem) => $this->lineItemArray($lineItem))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function lineItemArray(EstimateLineItem $lineItem): array
    {
        return [
            'id' => $lineItem->id,
            'line_item_id' => $lineItem->line_item_id,
            'name' => $lineItem->name,
            'category' => $lineItem->category,
            'sub_category' => $lineItem->sub_category,
            'unit_type' => $lineItem->unit_type,
            'quantity' => (float) $lineItem->quantity,
            'cost' => (float) $lineItem->cost,
            'total' => round((float) $lineItem->total, 2),
            'desc' => $lineItem->desc,
            'notes' => $lineItem->notes,
            'allowances' => $lineItem->allowances
                ->map(fn (EstimateLineItemAllowance $allowance) => [
                    'id' => $allowance->id,
                    'description' => $allowance->description,
                    'pricing_mode' => $allowance->pricing_mode ?? ($allowance->unit_amount !== null ? 'per_unit' : 'lump_sum'),
                    'unit_amount' => $allowance->unit_amount !== null ? (float) $allowance->unit_amount : null,
                    'amount' => $allowance->amount !== null ? (float) $allowance->amount : null,
                ])
                ->values()
                ->all(),
        ];
    }
}
