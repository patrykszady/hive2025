<?php

namespace App\Livewire\Estimates;

use App\Models\Bid;
use App\Models\Estimate;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

class EstimateAccept extends Component
{
    public Estimate $estimate;

    public Project $project;

    public $sections = [];

    public $bids = [];

    public $payments = [];

    public $payments_outstanding = 0;

    public $start_date = '';

    public $end_date = '';

    public bool $showSignWarning = false;

    /** @var array<int> Selected vendor user IDs who must sign */
    public array $requiredVendorSignerIds = [];

    protected $listeners = ['accept', 'signSetup', 'addPayment', 'refreshComponent' => 'refreshEstimateData'];

    protected function rules()
    {
        return [
            'sections.*.bid_index' => 'nullable',
            'payments.*.description' => 'required|min:3',
            'payments.*.amount' => 'nullable',
            'start_date' => 'nullable',
            'end_date' => 'nullable',
        ];
    }

    public function mount(Estimate $estimate)
    {
        $this->estimate = $estimate;
        $this->project = $estimate->project;

        // Load required vendor signer IDs from options, fall back to vendor defaults
        $this->requiredVendorSignerIds = $estimate->options['required_vendor_signer_ids'] ?? [];

        if (empty($this->requiredVendorSignerIds)) {
            $vendorDefaults = (array) data_get($estimate->vendor?->options, 'default_contract_signers', []);
            if (! empty($vendorDefaults)) {
                $this->requiredVendorSignerIds = $vendorDefaults;
            }
        }

        $this->bids = $this->project->bids()->vendorBids($this->estimate->vendor->id)->with('estimate_sections')->orderBy('type')->get();

        if ($this->bids->isEmpty()) {
            $bid = Bid::create([
                'amount' => 0.00,
                'type' => 1,
                'project_id' => $this->project->id,
                'vendor_id' => auth()->user()->vendor->id,
            ]);

            $this->bids->push($bid);
        }

        $bids = $this->bids;

        if (isset($this->estimate->options['start_date'])) {
            $this->start_date = $estimate->options['start_date'] ?? '';
        }

        if (isset($this->estimate->options['end_date'])) {
            $this->end_date = $estimate->options['end_date'] ?? '';
        }

        $sections = $this->estimate
            ->estimate_sections()
            ->with('bid')
            ->get();
        
        $this->sections = $sections->map(function ($item, $key) use ($bids) {
            $sectionArray = $item->toArray();
            
            if ($item->bid) {
                $bid_index = $bids->search(function ($bid) use ($item) {
                    return $item->bid->id === $bid->id;
                });
                // If search returns false (not found), default to 0
                $sectionArray['bid_index'] = $bid_index !== false ? $bid_index : 0;
            } else {
                // Default to the first bid (Original Bid) if no bid is associated
                $sectionArray['bid_index'] = 0;
            }
            
            return (object) $sectionArray;
        });
        if ($this->estimate->payments) {
            $this->payments = collect($this->estimate->payments)->map(fn ($p) => [
                'amount' => $p['amount'] ?? '',
                'description' => $p['description'] ?? '',
            ]);
        } else {
            $this->payments = [
                0 => [
                    'amount' => '',
                    'description' => '',
                ],
            ];

            $this->payments = collect($this->payments);
        }
    }

    /**
     * Vendor users available to be selected as required signers.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\User>
     */
    #[Computed]
    public function vendorUsers(): \Illuminate\Support\Collection
    {
        return $this->estimate->vendor?->users()
            ->wherePivot('role_id', 1)
            ->wherePivot('is_employed', 1)
            ->get() ?? collect();
    }

    public function accept()
    {
        $this->showSignWarning = false;
        $this->modal('accept_estimate_modal')->show();
    }

    public function signSetup()
    {
        $this->showSignWarning = true;
        $this->modal('accept_estimate_modal')->show();
    }

    public function refreshEstimateData()
    {
        // Refresh the estimate model to get fresh data
        $this->estimate = $this->estimate->fresh();
        $this->project = $this->estimate->project;

        // Reload required vendor signer IDs
        $this->requiredVendorSignerIds = $this->estimate->options['required_vendor_signer_ids'] ?? [];

        // Reload bids to include any newly created change orders
        $this->bids = $this->project->bids()->vendorBids($this->estimate->vendor->id)->with('estimate_sections')->orderBy('type')->get();

        if ($this->bids->isEmpty()) {
            $bid = Bid::create([
                'amount' => 0.00,
                'type' => 1,
                'project_id' => $this->project->id,
                'vendor_id' => auth()->user()->vendor->id,
            ]);

            $this->bids->push($bid);
        }

        // Reload sections with fresh data
        $sections = $this->estimate
            ->estimate_sections()
            ->with('bid')
            ->get();
        
        $this->sections = $sections->map(function ($item, $key) {
            $sectionArray = $item->toArray();
            
            if ($item->bid) {
                $bid_index = $this->bids->search(function ($bid) use ($item) {
                    return $item->bid->id === $bid->id;
                });
                // If search returns false (not found), default to 0
                $sectionArray['bid_index'] = $bid_index !== false ? $bid_index : 0;
            } else {
                // Default to the first bid (Original Bid) if no bid is associated
                $sectionArray['bid_index'] = 0;
            }
            
            return (object) $sectionArray;
        });
    }

    //new estiamte Bid
    public function newEstimateBid($section_index)
    {
        $bid_index = $this->bids->max('type');
        $bid = Bid::create([
            'amount' => 0.00,
            'type' => $bid_index + 1,
            'project_id' => $this->project->id,
            'vendor_id' => auth()->user()->vendor->id,
        ]);
        $this->bids->push($bid);
        $this->sections[$section_index]->bid_index = $bid_index;
    }

    public function getPaymentsRemainingProperty()
    {
        $sections_total = $this->sections->where('bid_index', 0)->sum('total');
        $payments_sum = $this->payments->where('amount', '!=', '')->sum('amount');
        $this->payments_outstanding = round($sections_total - $payments_sum, 2);

        return $this->payments_outstanding;
    }

    #[Computed]
    public function uniquePaymentDescriptions(): array
    {
        $descriptions = Estimate::withoutGlobalScopes()
            ->where('belongs_to_vendor_id', $this->estimate->belongs_to_vendor_id)
            ->whereNotNull('options->payments')
            ->pluck('options')
            ->flatMap(fn (array $options) => collect($options['payments'] ?? []))
            ->pluck('description')
            ->filter()
            ->map(fn ($description) => $this->canonicalPaymentDescription((string) $description))
            ->filter(fn (string $description) => $description !== '');

        $uniqueByNormalizedValue = [];

        foreach ($descriptions as $description) {
            $normalizedDescription = $this->normalizedPaymentDescription($description);

            if (! array_key_exists($normalizedDescription, $uniqueByNormalizedValue)) {
                $uniqueByNormalizedValue[$normalizedDescription] = $description;
            }
        }

        return collect(array_values($uniqueByNormalizedValue))
            ->sort(fn (string $a, string $b) => strcasecmp($a, $b))
            ->values()
            ->all();
    }

    public function availableDescriptions(int $index): array
    {
        $selectedNormalized = $this->payments
            ->pluck('description')
            ->filter()
            ->map(fn ($description) => $this->normalizedPaymentDescription((string) $description))
            ->filter(fn (string $description) => $description !== '');

        $currentValue = (string) ($this->payments[$index]['description'] ?? '');
        $currentValueNormalized = $this->normalizedPaymentDescription($currentValue);

        return collect($this->uniquePaymentDescriptions)
            ->reject(function (string $description) use ($currentValueNormalized, $selectedNormalized): bool {
                $normalizedDescription = $this->normalizedPaymentDescription($description);

                return $normalizedDescription !== $currentValueNormalized
                    && $selectedNormalized->contains($normalizedDescription);
            })
            ->values()
            ->all();
    }

    protected function canonicalPaymentDescription(string $description): string
    {
        $normalized = trim($description);
        $normalized = preg_replace('/\s*\/\s*/', '/', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return Str::title($normalized);
    }

    protected function normalizedPaymentDescription(string $description): string
    {
        return Str::lower($this->canonicalPaymentDescription($description));
    }

    protected function hasDuplicatePaymentDescriptions(): bool
    {
        $seenDescriptions = [];

        foreach ($this->payments as $index => $payment) {
            $normalizedDescription = $this->normalizedPaymentDescription((string) ($payment['description'] ?? ''));

            if ($normalizedDescription === '') {
                continue;
            }

            if (array_key_exists($normalizedDescription, $seenDescriptions)) {
                $this->addError("payments.{$seenDescriptions[$normalizedDescription]}.description", 'Payment descriptions must be unique.');
                $this->addError("payments.{$index}.description", 'Payment descriptions must be unique.');

                return true;
            }

            $seenDescriptions[$normalizedDescription] = $index;
        }

        return false;
    }

    //new Payment split
    public function addPayment()
    {
        $payment = [
            'amount' => '',
            'description' => '',
        ];

        $this->payments->push($payment);
        $this->payments = $this->payments->values();
    }

    public function removePayment($index)
    {
        $this->payments->forget($index);
        $this->payments = $this->payments->values();
    }

    public function save()
    {
        if ($this->payments_outstanding < 0) {
            $this->addError('payments_remaining_error', 'Amount Remaining cannot be less than $0.00');
        } else {
            $estimate = $this->estimate;
            $estimate_options = $this->estimate->options;

            $this->payments = $this->payments
                ->map(function (array $payment): array {
                    $payment['description'] = $this->canonicalPaymentDescription((string) ($payment['description'] ?? ''));

                    return $payment;
                })
                ->values();

            if ($this->hasDuplicatePaymentDescriptions()) {
                $this->dispatch('notify',
                    type: 'error',
                    content: 'Payment descriptions must be unique.'
                );

                return;
            }

            if ($this->payments->where('amount', '!=', '')->sum('amount') != 0) {
                $estimate_options['payments'] = $this->payments->toArray();
            }

            $estimate_options['start_date'] = $this->start_date;
            $estimate_options['end_date'] = $this->end_date;

            // Persist required vendor signer IDs
            $estimate_options['required_vendor_signer_ids'] = array_map('intval', $this->requiredVendorSignerIds);

            $estimate->options = $estimate_options;
            $estimate->save();

            foreach ($this->bids as $bid_index => $bid) {
                $bid_sections = $this->sections->where('bid_index', $bid_index);

                if ($bid_sections->isEmpty()) {
                    // If no sections assigned to this bid, delete it (except for the first bid)
                    if ($bid_index != 0) {
                        $bid->delete();
                    } else {
                        // Keep the original bid but set amount to 0
                        $bid->amount = 0.00;
                        $bid->save();
                    }
                } else {
                    // Update bid amount and associate sections
                    $bid_amount = $bid_sections->sum('total');
                    $bid->amount = $bid_amount;
                    $bid->save();

                    foreach ($bid_sections as $section) {
                        // Find the actual EstimateSection model and update it
                        $sectionModel = $this->estimate->estimate_sections()->find($section->id);
                        if ($sectionModel) {
                            $sectionModel->bid_id = $bid->id;
                            $sectionModel->save();
                        }

                        // Keep the bid_index for UI purposes
                        $section->bid_index = $bid_index;
                    }
                }
            }

            $this->modal('accept_estimate_modal')->close();
            $this->dispatch('refreshComponent')->to('estimates.estimate-show');
            $this->dispatch('refresh')->to('projects.project-finances');

            $this->dispatch('notify',
                type: 'success',
                content: 'Estimate Finalized'
            );
        }
    }

    public function render()
    {
        return view('livewire.estimates.accept');
    }
}
