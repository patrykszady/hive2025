<?php

namespace App\Jobs\Permits;

use App\Models\VillageCodes;
use App\Services\Permits\VillageCodeFinder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Look a town's adopted building codes up on its own website, in the
 * background, the first time a permit packet needs them
 * (App\Livewire\Estimates\EstimatePermitPacket polls for the answer).
 */
class FindVillageCodes implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public string $town, public string $state = 'IL')
    {
        $this->onQueue('default');
    }

    public function handle(VillageCodeFinder $finder): void
    {
        $finder->find($this->town, $this->state);
    }

    public function failed(?\Throwable $e): void
    {
        VillageCodes::query()->named($this->town, $this->state)->where('status', VillageCodes::STATUS_PENDING)->update([
            'status' => VillageCodes::STATUS_FAILED,
            'last_error' => mb_substr((string) $e?->getMessage(), 0, 1000),
            'checked_at' => now(),
        ]);
    }
}
