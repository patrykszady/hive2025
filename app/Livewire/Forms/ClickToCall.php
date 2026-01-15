<?php

namespace App\Livewire\Forms;

use App\Services\TelnyxVoiceService;
use Livewire\Component;

/**
 * Click-to-Call Button Component
 *
 * A reusable Livewire component that allows staff to initiate
 * a click-to-call with a customer. Can be placed anywhere in the UI.
 *
 * Usage:
 *   <livewire:forms.click-to-call
 *       :customer-phone="$client->phone"
 *       :customer-name="$client->name"
 *       :project-id="$project->id"
 *   />
 */
class ClickToCall extends Component
{
    public string $customerPhone;

    public ?string $customerName = null;

    public ?int $projectId = null;

    public ?int $clientId = null;

    public ?int $clientUserId = null;

    public string $buttonText = 'Call';

    public string $buttonVariant = 'ghost';

    public string $buttonSize = 'sm';

    public bool $iconOnly = false;

    public bool $isLoading = false;

    public ?string $lastError = null;

    public ?string $lastSuccess = null;

    public function mount(
        string $customerPhone,
        ?string $customerName = null,
        ?int $projectId = null,
        ?int $clientId = null,
        ?int $clientUserId = null,
        string $buttonText = 'Call',
        string $buttonVariant = 'ghost',
        string $buttonSize = 'sm',
        bool $iconOnly = false
    ) {
        $this->customerPhone = $customerPhone;
        $this->customerName = $customerName;
        $this->projectId = $projectId;
        $this->clientId = $clientId;
        $this->clientUserId = $clientUserId;
        $this->buttonText = $buttonText;
        $this->buttonVariant = $buttonVariant;
        $this->buttonSize = $buttonSize;
        $this->iconOnly = $iconOnly;
    }

    /**
     * Initiate the click-to-call.
     */
    public function initiateCall(): void
    {
        $this->isLoading = true;
        $this->lastError = null;
        $this->lastSuccess = null;

        // Get the current user's phone number
        $user = auth()->user();
        $staffPhone = $user->phone ?? null;

        if (empty($staffPhone)) {
            $this->lastError = 'Your phone number is not configured. Please update your profile.';
            $this->isLoading = false;

            return;
        }

        if (empty($this->customerPhone)) {
            $this->lastError = 'Customer phone number is missing.';
            $this->isLoading = false;

            return;
        }

        try {
            $voiceService = app(TelnyxVoiceService::class);

            if (! $voiceService->isConfigured()) {
                $this->lastError = 'Voice calling is not configured.';
                $this->isLoading = false;

                return;
            }

            $result = $voiceService->initiateClickToCall(
                staffPhone: $staffPhone,
                customerPhone: $this->customerPhone,
                customerName: $this->customerName,
                projectName: $this->getProjectName()
            );

            $this->lastSuccess = 'Call initiated! Your phone will ring shortly.';

            // Dispatch browser event for optional toast notification
            $this->dispatch('call-initiated', callId: $result['callId'] ?? null);

        } catch (\Exception $e) {
            $this->lastError = 'Failed to initiate call. Please try again.';
        }

        $this->isLoading = false;
    }

    /**
     * Get project name if project ID is set.
     */
    protected function getProjectName(): ?string
    {
        if (! $this->projectId) {
            return null;
        }

        $project = \App\Models\Project::find($this->projectId);

        return $project?->name;
    }

    public function render()
    {
        return view('livewire.forms.click-to-call');
    }
}
