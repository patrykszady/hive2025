<?php

namespace App\Livewire\Forms;

use App\Models\Payment;
use App\Services\Payments\PaymentRecorder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Rule;
use Livewire\Form;

class PaymentForm extends Form
{
    use AuthorizesRequests;

    public ?Payment $payment;

    #[Rule('required|date|before_or_equal:today|after:2017-01-01')]
    public $date = null;

    #[Rule('required')]
    public $invoice = null;

    #[Rule('nullable')]
    public $note = null;

    public function setPayment(Payment $payment)
    {
        $this->payment = $payment;

        // Prefill form fields
        $this->date = $this->payment->date->format('Y-m-d');
        $this->invoice = $this->payment->reference;
        $this->note = $this->payment->note;

        // Prefill project amounts for the edit modal.
        // Assumes the component (PaymentCreate) has already loaded $projects for the client.
        $component = $this->component; // Livewire component using this form
        if (isset($component->projects) && !empty($component->projects)) {
            $group = $this->payment->payments; // Collection of grouped payments (parent+children or transaction group)
            $amountsByProject = $group->mapWithKeys(fn($p) => [$p->project_id => $p->amount]);
            foreach ($component->projects as $index => $proj) {
                if (!is_array($proj)) {
                    continue;
                }

                $projectId = $proj['id'] ?? null;
                if ($projectId === null) {
                    continue;
                }

                $rawAmount = $amountsByProject[$projectId]
                    ?? ($component->projects[$index]['amount'] ?? null);
                $component->projects[$index]['amount'] = $this->formatAmount($rawAmount);
            }
        }
    }

    public function store()
    {
        $this->validate();

        // The creation logic (filtering, amount parsing, parent/child
        // grouping) lives in PaymentRecorder, shared with the Hive
        // Contractors app's PaymentController::store() — see its docblock.
        return app(PaymentRecorder::class)->record(
            auth()->user(),
            $this->component->projects ?? [],
            (string) $this->date,
            (string) $this->invoice,
            $this->note,
        );
    }

    public function update()
    {
        $this->validate();

        // The edit logic (which projects stay/go, parent/child grouping,
        // collapsing to a single project) lives in PaymentRecorder, shared
        // with the Hive Contractors app's PaymentController::update() — see
        // its docblock.
        return app(PaymentRecorder::class)->update(
            $this->payment,
            $this->component->projects ?? [],
            (string) $this->date,
            (string) $this->invoice,
            $this->note,
            auth()->user(),
        );
    }

    private function formatAmount($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
