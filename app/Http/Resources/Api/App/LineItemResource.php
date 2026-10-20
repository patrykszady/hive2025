<?php

namespace App\Http\Resources\Api\App;

use App\Models\LineItemAllowance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\LineItem
 */
class LineItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'desc' => $this->desc,
            'notes' => $this->notes,
            'category' => $this->category,
            'sub_category' => $this->sub_category,
            'unit_type' => $this->unit_type,
            'cost' => (float) $this->cost,
            'created_at' => $this->created_at?->toIso8601String(),
            'allowances' => $this->resource->allowances
                ->map(fn (LineItemAllowance $allowance) => [
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
