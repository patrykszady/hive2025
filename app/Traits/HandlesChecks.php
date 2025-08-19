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
    
    /**
     * Associate expense(s) with a check
     * 
     * @param \App\Models\Check $check - The check to associate expenses with
     * @param \Illuminate\Support\Collection|\App\Models\Expense|array $expenses - Expense(s) to associate
     * @param bool $updateCheckAmount - Whether to update the check amount based on expenses
     * @return \App\Models\Check
     */
    public function associateExpensesToCheck($check, $expenses, $updateCheckAmount = true)
    {
        // Convert single expense to collection
        if ($expenses instanceof \App\Models\Expense) {
            $expenses = collect([$expenses]);
        } elseif (is_array($expenses)) {
            $expenses = collect($expenses);
        }
        
        // Calculate total expense amount
        $totalAmount = 0;
        
        foreach ($expenses as $expense) {
            // Skip if already associated with this check
            if ($expense->check_id == $check->id) {
                $totalAmount += $expense->amount;
                continue;
            }
            
            // If expense is already associated with another check, detach it first
            if ($expense->check_id) {
                $oldCheck = \App\Models\Check::find($expense->check_id);
                if ($oldCheck) {
                    $oldCheck->amount -= $expense->amount;
                    $oldCheck->save();
                }
            }
            
            // Update expense with new check ID
            $expense->update([
                'check_id' => $check->id
            ]);
            
            $totalAmount += $expense->amount;
        }
        
        // Update check amount if requested
        if ($updateCheckAmount) {
            $check->amount = $totalAmount;
            $check->save();
        }
        
        return $check;
    }

    /**
     * Create a check and associate expenses with it
     * 
     * @param array $checkData - Data for creating the check
     * @param \Illuminate\Support\Collection|\App\Models\Expense|array $expenses - Expense(s) to associate
     * @return \App\Models\Check
     */
    public function createCheckWithExpenses($checkData, $expenses)
    {
        // Create the check
        $check = \App\Models\Check::create($checkData);
        
        // Associate expenses with the check
        return $this->associateExpensesToCheck($check, $expenses);
    }

    /**
     * Find or create a check based on bank_account_id, check_type, and check_number
     * and associate expenses with it
     * 
     * @param array $checkData - Data for finding/creating the check
     * @param \Illuminate\Support\Collection|\App\Models\Expense|array $expenses - Expense(s) to associate
     * @return \App\Models\Check
     */
    public function findOrCreateCheckForExpenses($checkData, $expenses)
    {
        // Required fields for finding a check
        $requiredFields = ['bank_account_id', 'check_type', 'check_number', 'vendor_id'];
        
        // Check if all required fields are present
        foreach ($requiredFields as $field) {
            if (!isset($checkData[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }
        
        // Try to find an existing check with the same details
        $existingCheck = null;
        
        if ($checkData['check_type'] == 'Check' && !empty($checkData['check_number'])) {
            $existingCheck = \App\Models\Check::where('deleted_at', null)
                ->where('bank_account_id', $checkData['bank_account_id'])
                ->where('check_type', $checkData['check_type'])
                ->where('check_number', $checkData['check_number'])
                ->where('vendor_id', $checkData['vendor_id'])
                ->first();
        }
        
        if ($existingCheck) {
            // Use the existing check
            return $this->associateExpensesToCheck($existingCheck, $expenses);
        } else {
            // Create a new check
            return $this->createCheckWithExpenses($checkData, $expenses);
        }
    }
}
