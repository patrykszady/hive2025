<?php

namespace App\Livewire\Forms;

use App\Models\Payment;
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

        if ($this->payment->payments_grouped->isEmpty()) {
            // $payments = $this->payment->payments_grouped;
            // $payments->push($this->payment);
            $parent_payment = Payment::where('parent_client_payment_id', $this->payment->id)->get();
            $parent_payment->push($this->payment);
            $payments->push($parent_payment);
        } else {
            $payments = $this->payment->payments_grouped;
        }

        dd($payments);
        // $this->payment = $payment;
        dd('in past');

        $this->date = $this->payment->date->format('Y-m-d');
        $this->invoice = $this->payment->reference;
        $this->note = $this->payment->note;
    }

    public function store()
    {
        $this->validate();

        $parent_payment_id = null;
        foreach ($this->component->projects->where('amount', '!=', null) as $key => $project) {
            if ($key == 0) {
                $parent_payment_id = null;
            } else {
                $parent_payment_id = $parent_payment_id;
            }

            $payment = Payment::create([
                'amount' => $project->amount,
                'project_id' => $project->id,
                'date' => $this->date,
                'reference' => $this->invoice,
                'belongs_to_vendor_id' => auth()->user()->primary_vendor_id,
                'note' => $this->note,
                'created_by_user_id' => auth()->user()->id,
                'parent_client_payment_id' => $parent_payment_id,
            ]);

            $parent_payment_id = $payment->id;
        }

        return $payment;
    }
}
