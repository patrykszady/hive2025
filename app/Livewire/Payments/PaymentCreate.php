<?php

namespace App\Livewire\Payments;

use App\Livewire\Forms\PaymentForm;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;

use Carbon\Carbon;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Flux;

class PaymentCreate extends Component
{
    use AuthorizesRequests;

    public PaymentForm $form;

    public Payment $payment;

    public Client $client;

    public $client_id = null;

    public $projects = [];

    public $view = false;

    public $from_project = false;

    public $view_text = [
        'card_title' => 'Create Client Payment',
        'button_text' => 'Add Payment',
        'form_submit' => 'save',
    ];

    protected $listeners = ['addProject', 'removeProject', 'editPayment'];

    protected function rules()
    {
        return [
            'client_id' => 'nullable',
            'projects.*.amount' => [
                'nullable',
                'numeric',
                'regex:/^-?\d+(\.\d{1,2})?$/',
                function ($attribute, $value, $fail) {
                    if ($value === null || $value === '') {
                        return; // allow empty; handled by sum check separately
                    }
                    if ((float) $value == 0.0) {
                        $fail('Amount cannot be 0.00');
                    }
                },
            ],
        ];
    }

    public function mount()
    {
        $this->authorize('create', Payment::class);
        // Date will be set by browser's local date via Alpine.js
    }

    public function updated($field, $value): void
    {
        if (preg_match('/^projects\.(\d+)\.amount$/', (string) $field, $matches) === 1) {
            $index = (int) $matches[1];
            $sanitized = $this->sanitizeAmount($value);
            if (isset($this->projects[$index]) && is_array($this->projects[$index])) {
                $this->projects[$index]['amount'] = $sanitized;
            }
        }

        $this->validateOnly($field);
    }

    public function updatedClientId(Client $client)
    {
        $this->client = $client;
        $this->projects = $client->projects()
            ->status([6, 7, 8]) // Active, Complete, Service Call
            ->with('latestStatus')
            ->get()
            ->filter(function ($project) {
                // Always include Active projects
                if ($project->latestStatus->status_code === 6) {
                    return true;
                }
                
                // For completed projects, only include if balance > 0
                if (in_array($project->latestStatus->status_code, [7, 8])) {
                    return $project->finances['balance'] > 0;
                }
                
                return true;
            })
            ->sortBy([
                ['latestStatus.status_code', 'asc'],
                ['latestStatus.start_date', 'desc'],
            ])
            ->map(function ($project) {
                return [
                    'id' => $project->id,
                    'address' => $project->address,
                    'project_name' => $project->project_name,
                    'amount' => null,
                ];
            })
            ->values()
            ->toArray();
    }

    public function editPayment(Payment $payment)
    {
        $this->payment = $payment;
        $this->client = $payment->project->client;
        $this->client_id = $payment->project->client->id;
        $this->updatedClientId($this->client);
        $this->form->setPayment($this->payment);
        $this->from_project = true; // Always disable client selection when editing

        $this->view_text = [
            'card_title' => 'Update Client Payment',
            'button_text' => 'Update Payment',
            'form_submit' => 'update',
        ];

        $this->modal('payment_form_modal')->show();
    }

    #[Computed]
    public function clients()
    {
        // The dropdown renders $client->name, which reads client->users —
        // without this eager load that was one query per option.
        return Client::withWhereHas('projects', function ($query) {
            $query->status([6, 7, 8]); // Active, Complete, Service Call
        })->with('users:id,first_name,last_name,nickname')
        ->orderBy('created_at', 'DESC') // Order clients by their own created_at date
        ->get();
    }

    #[Computed]
    public function hasValidProjects()
    {
        return collect($this->projects)->isNotEmpty();
    }

    public function getClientPaymentSumProperty()
    {
        return collect($this->projects)
            ->where('amount', '!=', null)
            ->sum(fn ($project) => $this->parseAmount($project['amount'] ?? null));
    }

    // 8-31-2022 | 9-10-2023 similar on VendorPaymentForm
    public function addProject(?Client $client = null)
    {
        $this->view_text = [
            'card_title' => 'Create Client Payment',
            'button_text' => 'Add Payment',
            'form_submit' => 'save',
        ];

        if (isset($client->id)) {
            $this->view = true;
            $this->from_project = true; // Coming from project view
            $this->client_id = $client->id;
            $this->updatedClientId($client);
        } else {
            $this->client_id = null;
            $this->from_project = false; // Coming from payments index
        }

        $this->modal('payment_form_modal')->show();
    }

    public function save()
    {
        $this->normalizeProjectAmounts();
        $this->validate();
        //validate payment total is greater than $0
        //if less than or equal to 0... send back with error
        if ($this->getClientPaymentSumProperty() === 0) {
            return $this->addError('payment_total_min', 'Payment total needs to include at least 1 project and not equal $0.00');
        } else {
            $payment = $this->form->store();
        }

        $this->modal('payment_form_modal')->close();
        $this->dispatch('refreshComponent')->to('payments.payments-index');
    }

    public function update()
    {
        // $this->authorize('update', $this->payment ?? Payment::class);

        $this->normalizeProjectAmounts();
        $this->validate();
        // Optional: allow zero if editing? Keep same validation as save for consistency
        if ($this->getClientPaymentSumProperty() === 0) {
            return $this->addError('payment_total_min', 'Payment total needs to include at least 1 project and not equal $0.00');
        }

        $payment = $this->form->update();

        $this->modal('payment_form_modal')->close();
        $this->dispatch('refreshComponent')->to('payments.payment-show');
    }

    private function normalizeProjectAmounts(): void
    {
        foreach ($this->projects as $index => $project) {
            if (!is_array($project)) {
                continue;
            }

            $this->projects[$index]['amount'] = $this->sanitizeAmount($project['amount'] ?? null);
        }
    }

    private function sanitizeAmount($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);
        if ($clean === null || $clean === '' || $clean === '-' || $clean === '.') {
            return null;
        }

        return $clean;
    }

    private function parseAmount($value): float
    {
        $sanitized = $this->sanitizeAmount($value);
        return $sanitized === null ? 0.0 : (float) $sanitized;
    }

    public function remove()
    {
        // $this->authorize('delete', $this->payment);

        $projectId = $this->payment->project_id;
        $this->payment->delete();

        // Close modal and redirect away from a possibly stale page
        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Payment Deleted',
            text: 'Payment removed successfully.',
        );
        $this->modal('payment_form_modal')->close();

        return $this->redirectRoute('projects.show', ['project' => $projectId], navigate: true);
    }

    #[Title('Payment')]
    public function render()
    {
        $this->authorize('create', Payment::class);
        return view('livewire.payments.form');
    }
}
