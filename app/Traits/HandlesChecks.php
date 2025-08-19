<?php

namespace App\Traits;

use App\Models\Check;
use App\Models\BankAccount;

use Illuminate\Validation\Rule;

trait HandlesChecks
{
    // These properties are defined on the parent component.
    public $bank_account_id = '';  // Bound via wire:model.live="bank_account_id"
    public $check_type = '';
    public $check_number = null;
    public $next_check_auto = false;
    public $auto_check_number = null; // Store the auto-generated value for comparison

    /**
     * Returns the check-related validation rules.
     */
    public function handlesChecksRules(): array
    {
        return [
            'bank_account_id' => 'required_without:form.paid_by',
            'check_type'      => ['required_with:bank_account_id'],
            'next_check_auto' => ['nullable'],
            'check_number'    => ['required_if:check_type,Check', 'nullable', 'numeric'],
        ];
    }

    /**
     * Merge any extra rules with the handles-check rules.
     */
    public function mergedRules(array $extraRules = []): array
    {
        return array_merge($extraRules, $this->handlesChecksRules());
    }

    /**
     * Prefix an array of rules.
     *
     * For nested form rules, we add a "form." prefix.
     */
    protected function prefixRules(array $rules, string $prefix = 'form.'): array
    {
        $prefixed = [];
        foreach ($rules as $key => $rule) {
            $prefixed[$prefix . $key] = $rule;
        }
        return $prefixed;
    }

    /**
     * Merge the nested form rules (after prefixing) with the handles-check rules
     * and any additional rules.
     */
    protected function componentMergedRules(array $formRules, array $additionalRules = []): array
    {
        return array_merge(
            $this->mergedRules($this->prefixRules($formRules)),
            $additionalRules
        );
    }

    /**
     * A helper that encapsulates the common "updated" logic for check-related fields.
     *
     * Call this from your component's updated() method.
     */
    public function handleChecksUpdated($field, $value)
    {
        if ($field === 'form.paid_by') {
            // When paid_by changes, reset various check–related values.
            $this->check_type       = '';
            $this->check_number     = null;
            $this->auto_check_number = null;
            $this->bank_account_id  = '';
            $this->next_check_auto  = false;
            // Reset the invoice on the nested form.
            $this->form->invoice    = null;
        }

        if ($field === 'form.invoice') {
            $this->validateOnly($field);
        }

        if ($field === 'bank_account_id') {
            $this->validateOnly($field);
            // Validate paid_by too—its rule may depend on bank_account_id.
            $this->validateOnly('form.paid_by');
            $this->check_type   = '';
            $this->check_number = null;
            $this->auto_check_number = null;
            $this->next_check_auto = false;
        }

        if ($field === 'check_type') {
            if ($value !== 'Check') {
                $this->check_number    = null;
                $this->auto_check_number = null;
                $this->next_check_auto = false;
            } else {
                $this->autoCheckNumber();
            }
            $this->validateOnly($field);
        }
        
        // Handle manual changes to check_number
        if ($field === 'check_number' && $this->next_check_auto) {
            // If auto-generated check number was changed manually
            if ($this->auto_check_number != $value) {
                $this->next_check_auto = false;
            }
        }
    }

    public function getBankAccountsProperty()
    {
        return BankAccount::latestCheckingAccounts()->get();
    }

    public function autoCheckNumber()
    {
        $bank_account_ids = $this->bank_accounts->find($this->bank_account_id)->bank->accounts()->withoutGlobalScopes()->pluck('id')->toArray();
        $next_check_number = Check::whereIn('bank_account_id', $bank_account_ids)->where('check_type', 'Check')->orderBy('date', 'DESC')->orderBy('created_at', 'DESC')->first()->check_number + 1;
        $this->check_number = $next_check_number;
        $this->auto_check_number = $next_check_number; // Store the auto-generated value
        $this->next_check_auto = true;
    }
}
