<?php

namespace App\Livewire\LienWaivers;

use App\Models\LienWaiver;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Authenticated lien waiver detail page. There is no e-sign flow: waivers are
 * printed, wet-signed, notarized, and scanned back to waivers@hive.contractors
 * where the barcode ingest matches them and flips the status to Signed.
 */
class Show extends Component
{
    #[Locked]
    public int $waiverId;

    public function mount(?LienWaiver $lienWaiver = null): void
    {
        abort_unless($lienWaiver && $lienWaiver->exists, 404);
        $this->waiverId = $lienWaiver->id;
    }

    #[Computed]
    public function waiver(): LienWaiver
    {
        return LienWaiver::query()
            ->with(['vendor', 'payerVendor', 'project.client', 'project.estimates', 'check', 'payment'])
            ->findOrFail($this->waiverId);
    }

    #[Title('Lien Waiver')]
    public function render()
    {
        return view('livewire.lien-waivers.show');
    }
}
