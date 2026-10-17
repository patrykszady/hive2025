<?php

namespace App\Livewire\Sms;

use App\Livewire\Sms\Concerns\HasCallActions;
use App\Models\BlockedCaller;
use App\Models\CallLog;
use App\Support\Calls\CallTranscriptPresenter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\On;
use Livewire\Component;

#[Isolate]
class CallDetail extends Component
{
    use HasCallActions;

    public ?int $callId = null;

    #[On('call-selected')]
    public function selectCall(int $callId): void
    {
        $this->callId = $callId;
    }

    #[On('call-deselected')]
    public function clear(): void
    {
        $this->callId = null;
    }

    #[Computed]
    public function call(): ?CallLog
    {
        if (! $this->callId) {
            return null;
        }

        $user = auth()->user();

        return CallLog::query()
            ->with('transcript')
            ->visibleToMessagesUser($user)
            ->find($this->callId);
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function blockedNumbers(): array
    {
        $vendorId = auth()->user()->vendor?->id;

        return BlockedCaller::where(fn ($q) => $q->whereNull('vendor_id')->orWhere('vendor_id', $vendorId))
            ->pluck('phone_number')
            ->all();
    }

    public function effectiveStatus(CallLog $call): string
    {
        return \App\Services\Calls\CallHistory::effectiveStatus($call);
    }

    /**
     * Transcript segments with our own welcome and disclosure stripped and
     * speakers named (CallTranscriptPresenter, shared with the app's API).
     *
     * @return array<int, array{text: string, speaker: ?string, start: float|int|null, end: float|int|null}>
     */
    public function cleanedTranscriptSegments(CallLog $call): array
    {
        return app(CallTranscriptPresenter::class)->segments($call);
    }

    /**
     * Distinct speaker names in the cleaned transcript.
     *
     * @return array<int, string>
     */
    public function transcriptSpeakers(CallLog $call): array
    {
        return app(CallTranscriptPresenter::class)->speakers($call);
    }

    public function render()
    {
        return view('livewire.sms.call-detail');
    }
}
