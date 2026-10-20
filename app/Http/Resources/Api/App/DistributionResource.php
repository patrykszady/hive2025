<?php

namespace App\Http\Resources\Api\App;

use App\Models\Distribution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A distribution as the app's Distributions tab shows it — the
 * /distributions table row (App\Livewire\Distributions\DistributionsList)
 * by default, or the detail breakdown (DistributionsShow) with
 * withDetail(). Creating a distribution (name + an eligible team member)
 * is the only write this mirrors; project-percentage splits stay web-only.
 *
 * @mixin \App\Models\Distribution
 */
class DistributionResource extends JsonResource
{
    protected bool $detail = false;

    /** @var array{vendors: Collection, earned: float, paid: float}|null */
    protected ?array $breakdown = null;

    /**
     * @param  array{vendors: Collection, earned: float, paid: float}  $breakdown
     */
    public function withBreakdown(array $breakdown): static
    {
        $this->detail = true;
        $this->breakdown = $breakdown;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Distribution $distribution */
        $distribution = $this->resource;

        $row = [
            'id' => $distribution->id,
            'name' => $distribution->name,
            'web_path' => '/distributions/'.$distribution->id,
        ];

        if ($this->breakdown === null && isset($distribution->balance)) {
            // The index row — DistributionFigures::ytdTable() already
            // stamped these onto the model.
            return $row + [
                'balance' => round((float) $distribution->balance, 2),
                'ytd_earned' => round((float) $distribution->ytd_earned, 2),
                'ytd_paid' => round((float) $distribution->ytd_paid, 2),
                'ytd_balance' => round((float) $distribution->ytd_balance, 2),
            ];
        }

        if (! $this->detail || $this->breakdown === null) {
            return $row;
        }

        return $row + [
            'earned' => round((float) $this->breakdown['earned'], 2),
            'paid' => round((float) $this->breakdown['paid'], 2),
            'balance' => round((float) $this->breakdown['earned'] - (float) $this->breakdown['paid'], 2),
            'vendors' => $this->breakdown['vendors']->map(fn ($group) => [
                'id' => $group->vendor?->id,
                'name' => $group->vendor?->business_name,
                'amount' => round((float) $group->sum, 2),
            ])->values()->all(),
        ];
    }
}
