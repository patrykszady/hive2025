<?php

namespace App\Livewire\Forms;

use App\Services\Vendors\VendorPaymentRecorder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Form;

class VendorPaymentForm extends Form
{
    public $date = null;
    public $paid_by = null;
    public $invoice = null;

    public function rules(): array
    {
        $vendorId = auth()->user()->vendor->id;

        return [
            'date'    => 'required|date|before_or_equal:today|after:2017-01-01',
            'paid_by' => [
                'nullable',
                "required_if:bank_account_id,\"\"",
                Rule::exists('user_vendor', 'user_id')->where('vendor_id', $vendorId)->where('is_employed', 1),
            ],
            'invoice' => 'required_with:paid_by',
        ];
    }

    /**
     * @see VendorPaymentRecorder::record() — the actual write, shared with
     * the app's VendorPaymentController.
     */
    public function store()
    {
        $projects = collect($this->component->projects)
            ->filter(fn (array $project) => $project['show'] ?? false)
            ->map(fn (array $project) => ['id' => $project['id'], 'amount' => $project['amount'] ?? null])
            ->values()
            ->all();

        $reimbursementIds = collect($this->component->vendor_reimbursement_expenses)
            ->filter(fn ($expense) => $this->component->selectedVendorReimbursementExpenses[$expense->id] ?? false)
            ->pluck('id')
            ->all();

        try {
            return app(VendorPaymentRecorder::class)->record(
                auth()->user(),
                $this->component->vendor,
                (string) $this->date,
                $this->component->check_type ?: null,
                $this->toNullableInt($this->component->check_number),
                $this->toNullableInt($this->component->bank_account_id),
                $this->toNullableInt($this->paid_by),
                $this->invoice,
                $projects,
                $reimbursementIds,
            );
        } catch (ValidationException $e) {
            // The service's keys are neutral (no component); re-prefix them
            // the way every other field on this form is nested, so the
            // blade's @error('form.paid_by') still catches it.
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn (array $messages, string $key) => ["form.$key" => $messages[0]])->all()
            );
        }
    }

    private function toNullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}
