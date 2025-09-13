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

    /**
     * Projects keyed by id as plain arrays so transient UI state (amount) persists across requests.
     * [ project_id => ['id'=>int,'address'=>string,'project_name'=>string,'amount'=>float|null] ]
     */
    public array $projects = [];

    public $view = false;

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
        $this->form->date = today()->format('Y-m-d');
    }

    public function updated($field)
    {
        // $this->validate();
        $this->validateOnly($field);
    }

    public function updatedClientId(Client $client)
    {
        $this->client = $client;
        $projectsCollection = $client->projects()
            ->orderBy('projects.created_at', 'DESC')
            ->status(['Active', 'Complete', 'Service Call', 'Service Call Complete'])
            ->get();

        $this->projects = $projectsCollection->mapWithKeys(function ($p) {
            return [
                $p->id => [
                    'id' => $p->id,
                    'address' => $p->address,
                    'project_name' => $p->project_name,
                    'amount' => null,
                ],
            ];
        })->toArray();
    }

    public function editPayment(Payment $payment)
    {
        $this->payment = $payment;
        $this->client = $payment->project->client;
        $this->client_id = $payment->project->client->id;
        $this->updatedClientId($this->client);
        // Prefill amount for the project tied to this payment if present
        if (isset($this->projects[$payment->project_id])) {
            $this->projects[$payment->project_id]['amount'] = $payment->amount;
        }
        $this->form->setPayment($this->payment);

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
        return Client::withWhereHas('projects', function ($query) {
            $query->status(['Active', 'Complete', 'Service Call', 'Service Call Complete']); // Use the new scope to filter statuses
        })->orderBy('created_at', 'DESC') // Order clients by their own created_at date
        ->get();
    }

    public function getClientPaymentSumProperty()
    {
        return collect($this->projects)
            ->filter(fn($p) => $p['amount'] !== null && $p['amount'] !== '')
            ->sum('amount');
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
            $this->client_id = $client->id;
            $this->updatedClientId($client);
        } else {
            $this->client_id = null;
        }

        $this->modal('payment_form_modal')->show();
    }

    public function save()
    {
    // Validate field-level rules first (allows null per-project amounts, enforces numeric and not zero when filled)
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

    // Validate field-level rules first
    $this->validate();
        // Optional: allow zero if editing? Keep same validation as save for consistency
        if ($this->getClientPaymentSumProperty() === 0) {
            return $this->addError('payment_total_min', 'Payment total needs to include at least 1 project and not equal $0.00');
        }

        $payment = $this->form->update();

        $this->modal('payment_form_modal')->close();
        $this->dispatch('refreshComponent')->to('payments.payment-show');
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
