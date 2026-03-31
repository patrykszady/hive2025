<?php

namespace App\Livewire\Entry;

use App\Jobs\ProcessVendorRegistrationMatching;
use App\Models\Distribution;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Livewire\Attributes\PublicProperty;
use Livewire\Component;

class VendorRegistration extends Component
{
    public Vendor $vendor;
    public $user;
    public $view;

    #[PublicProperty]
    public array $registration = [];

    public bool $registrationSubmitted = false;
    public int $registrationSubmittedAtMs = 0;
    public bool $dashboardRedirectDispatched = false;
    public ?string $matchingStatus = null;

    protected $listeners = ['refreshComponent' => '$refresh', 'confirmProcess'];

    public function mount(Request $request): void
    {
        $this->view = $request->route()->getName();
        $this->user = auth()->user();

        $this->user->update(['primary_vendor_id' => $this->vendor->id]);

        $registration = $this->vendor->registration;
        if (is_null($registration)) {
            $registration = [];
        }

        if (! is_array($registration)) {
            $registration = (array) $registration;
        }

        $this->registration = $registration;
        $this->matchingStatus = data_get($this->registration, 'matching.status');

        if (in_array($this->matchingStatus, ['queued', 'processing'], true)) {
            $this->registrationSubmitted = true;
            $this->registrationSubmittedAtMs = now()->valueOf();
        }

        $registrationSteps = $this->vendor->business_type === '1099'
            ? ['vendor_info', 'registered']
            : ['vendor_info', 'team_members', 'emails_registered', 'banks_registered', 'registered'];

        $dirty = false;
        foreach ($registrationSteps as $step) {
            if (! array_key_exists($step, $this->registration)) {
                $this->registration[$step] = false;
                $dirty = true;
            }
        }

        if ($dirty) {
            $this->vendor->forceFill(['registration' => $this->registration]);
            $this->vendor->save();
        }

        if (in_array($this->vendor->business_type, ['Sub', 'DBA'], true)) {
            if ($this->vendor->distributions->isEmpty()) {
                Distribution::create([
                    'vendor_id' => $this->user->vendor->id,
                    'name' => 'OFFICE',
                    'user_id' => 0,
                ]);

                Distribution::create([
                    'vendor_id' => $this->vendor->id,
                    'name' => $this->user->first_name . ' - Home',
                    'user_id' => $this->user->id,
                ]);
            }

            if ($this->vendor->company_emails()->exists() && empty($this->registration['emails_registered'])) {
                $this->confirmProcess('emails_registered');
            }

            if ($this->vendor->banks()->exists() && empty($this->registration['banks_registered'])) {
                $this->confirmProcess('banks_registered');
            }
        }
    }

    public function confirmProcess(string|array $process_step): void
    {
        if (is_array($process_step)) {
            $process_step = (string) ($process_step['process_step'] ?? '');
        }

        if ($process_step === '') {
            return;
        }

        $this->registration[$process_step] = true;
        $this->vendor->forceFill(['registration' => $this->registration]);
        $this->vendor->save();
    }

    public function getStepStatus(string $stepName): string
    {
        if (! empty($this->registration[$stepName])) {
            return 'complete';
        }

        $previousStep = $this->getPreviousStep($stepName);
        if ($previousStep === null || ! empty($this->registration[$previousStep])) {
            return 'current';
        }

        return 'incomplete';
    }

    public function getProgressValue(): int
    {
        $steps = $this->getRegistrationSteps();
        $total = count($steps) + 1; // +1 for owner step
        $completed = 1; // owner step always complete

        foreach ($steps as $step) {
            if (! empty($this->registration[$step['name']])) {
                $completed++;
            }
        }

        return (int) round(($completed / $total) * 100);
    }

    public function getPreviousStep(string $step): ?string
    {
        $steps = [
            'vendor_info' => null,
            'team_members' => 'vendor_info',
            'emails_registered' => 'team_members',
            'banks_registered' => 'emails_registered',
            'registered' => $this->vendor->business_type === '1099' ? 'vendor_info' : 'banks_registered',
        ];

        return $steps[$step] ?? null;
    }

    public function getRegistrationSteps(): array
    {
        $steps = [
            [
                'name' => 'vendor_info',
                'label' => 'Confirm',
                'description' => $this->vendor->name . ', ' . $this->vendor->business_type,
                'suffix' => 'Account',
                'icon' => 'briefcase',
            ],
        ];

        if ($this->vendor->business_type !== '1099') {
            $steps[] = [
                'name' => 'team_members',
                'label' => 'Add',
                'description' => 'Team Members',
                'suffix' => null,
                'icon' => 'user-plus',
            ];

            if (in_array($this->vendor->business_type, ['Sub', 'DBA'], true)) {
                $steps[] = [
                    'name' => 'emails_registered',
                    'label' => 'Add',
                    'description' => 'Receipt',
                    'suffix' => 'Accounts',
                    'icon' => 'envelope',
                ];

                $steps[] = [
                    'name' => 'banks_registered',
                    'label' => 'Add',
                    'description' => 'Transaction',
                    'suffix' => 'Accounts',
                    'icon' => 'credit-card',
                ];
            }
        }

        $steps[] = [
            'name' => 'registered',
            'label' => '',
            'description' => $this->vendor->name . ', ' . $this->vendor->business_type,
            'suffix' => 'registration complete',
            'icon' => 'check-circle',
        ];

        return $steps;
    }

    public function isStepVisible(string $stepName): bool
    {
        return (bool) ($this->registration[$stepName] ?? false);
    }

    public function refreshMatchingStatus(): void
    {
        $this->vendor->refresh();

        $registration = $this->vendor->registration;
        if (! is_array($registration)) {
            $registration = (array) $registration;
        }

        $this->registration = $registration;
        $this->matchingStatus = data_get($this->registration, 'matching.status');

        if ($this->matchingStatus === 'completed') {
            if ($this->registrationSubmitted && ! $this->dashboardRedirectDispatched) {
                $this->dashboardRedirectDispatched = true;

                $minDwellMs = 10_000;
                $elapsedMs = $this->registrationSubmittedAtMs > 0
                    ? (now()->valueOf() - $this->registrationSubmittedAtMs)
                    : $minDwellMs;

                $delayMs = max(0, $minDwellMs - $elapsedMs);

                $this->dispatch('vendor-registration:complete',
                    url: route('dashboard'),
                    delayMs: $delayMs,
                    fadeMs: 250,
                );

                return;
            }

            // JS redirect already dispatched — don't issue a server-side redirect
            // that would race against the client-side delay
            if ($this->dashboardRedirectDispatched) {
                return;
            }

            $this->redirect(route('dashboard'), navigate: true);
        }
    }

    public function store(): void
    {
        $this->confirmProcess('registered');

        $matching = (array) data_get($this->registration, 'matching', []);
        $matching['status'] = 'queued';
        $matching['updated_at'] = now()->toISOString();
        $matching['queued_at'] ??= now()->toISOString();
        $matching['error'] = null;

        $this->registration['matching'] = $matching;
        $this->vendor->forceFill(['registration' => $this->registration]);
        $this->vendor->save();

        ProcessVendorRegistrationMatching::dispatch($this->vendor->id, $this->user->id);

        $this->registrationSubmitted = true;
        $this->registrationSubmittedAtMs = now()->valueOf();
        $this->dashboardRedirectDispatched = false;
        $this->matchingStatus = 'queued';
    }

    public function render(): View
    {
        return view('livewire.entry.vendor-registration');
    }
}
