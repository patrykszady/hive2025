<?php

namespace App\Http\Resources\Api\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Category
 */
class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'primary' => $this->primary,
            'friendly_primary' => $this->friendly_primary,
            'detailed' => $this->detailed,
            'friendly_detailed' => $this->friendly_detailed,
        ];
    }
}
