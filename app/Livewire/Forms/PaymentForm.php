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
        $projects = collect($this->component->projects ?? [])
            ->filter(function ($p) {
                if (!is_array($p)) { return false; }
                return $this->parseAmount($p['amount'] ?? null) !== 0.0;
            })
            ->values();

        if ($projects->isEmpty()) {
            return null; // upstream component enforces non-zero total
        }

        $parentPaymentId = null;
        $lastPayment = null;
        foreach ($projects as $index => $project) {
            $amount = $this->parseAmount($project['amount'] ?? null);
            $payload = [
                'amount' => $amount,
                'project_id' => $project['id'],
                'date' => $this->date,
                'reference' => (string) $this->invoice,
                'belongs_to_vendor_id' => auth()->user()->vendor->id,
                'note' => $this->note,
                'created_by_user_id' => auth()->user()->id,
                'parent_client_payment_id' => $parentPaymentId,
            ];
            $lastPayment = Payment::create($payload);
            if ($index === 0) {
                $parentPaymentId = $lastPayment->id; // Subsequent become children of first
            }
        }
        return $lastPayment;
    }

    public function update()
    {
        $this->validate();

        // Determine the group root id (parent) for grouping
        $rootId = $this->payment->parent_client_payment_id ?: $this->payment->id;

        // Current grouped payments (collection)
        $currentGroup = $this->payment->payments; // includes parent+children

        // Build a quick index of existing payments by project_id
        $existingByProject = $currentGroup->keyBy('project_id');

        // Gather selected projects with an amount
        $component = $this->component;
        $selected = collect($component->projects ?? [])
            ->filter(fn($p) => is_array($p) && $this->parseAmount($p['amount'] ?? null) !== 0.0)
            ->unique('id')
            ->values();
        $selectedProjectIds = $selected->pluck('id')->all();

        // If switching to a single project, just update this payment record in place.
        if (count($selectedProjectIds) === 1) {
            $proj = $selected->first();
            $amount = $this->parseAmount($proj['amount'] ?? null);
            $this->payment->fill([
                'amount' => $amount,
                'project_id' => $proj['id'],
                'date' => $this->date,
                'reference' => (string) $this->invoice,
                'note' => $this->note,
                'belongs_to_vendor_id' => auth()->user()->vendor->id,
                'parent_client_payment_id' => null,
            ])->save();
            return $this->payment->fresh();
        }

        // Update existing or create new payments for selected projects
        foreach ($selected as $proj) {
            $amount = $this->parseAmount($proj['amount'] ?? null);
            $payload = [
                'amount' => $amount,
                'project_id' => $proj['id'],
                'date' => $this->date,
                'reference' => (string) $this->invoice,
                'note' => $this->note,
                'belongs_to_vendor_id' => auth()->user()->vendor->id,
            ];

            if ($existingByProject->has($proj['id'])) {
                // Update existing payment for this project
                $existing = $existingByProject[$proj['id']];
                $existing->fill($payload)->save();
            } else {
                // Extra guard: check DB for existing payment in this group with same project
                $dbExisting = Payment::where(function ($q) use ($rootId) {
                        $q->where('id', $rootId)->orWhere('parent_client_payment_id', $rootId);
                    })
                    ->where('project_id', $proj['id'])
                    ->first();

                if ($dbExisting) {
                    $dbExisting->fill($payload)->save();
                } else {
                    // Create a new child payment in this group
                    Payment::create(array_merge($payload, [
                        'parent_client_payment_id' => $rootId,
                        'created_by_user_id' => auth()->id(),
                    ]));
                }
            }
        }

        // Delete payments that are no longer selected (only within this group)
        foreach ($currentGroup as $existing) {
            if (!in_array($existing->project_id, $selectedProjectIds, true)) {
                $existing->delete();
            }
        }

        // Return the parent (or current) payment fresh for redirection context
        return Payment::find($rootId) ?? $this->payment->fresh();
    }

    private function parseAmount($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);
        if ($clean === null || $clean === '' || $clean === '-' || $clean === '.') {
            return 0.0;
        }

        return (float) $clean;
    }

    private function formatAmount($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
