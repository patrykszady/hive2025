<?php

namespace App\Http\Requests\Api\App;

use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\ExpenseSplits;
use App\Models\Vendor;
use App\Services\Checks\CheckNumberSuggester;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a new expense from the app's create form — the same rules
 * ExpenseForm's #[Validate] attributes and rules() enforce (amount format,
 * date window, project-required-unless-split), plus the splits array the
 * app's own simplified split editor sends.
 *
 * bank_account_id/check_type/check_number let the app record a brand-new
 * check the same way App\Traits\HandlesChecks' rules do for the web's own
 * expense form (ExpenseCreate uses that trait for its bank_account_id/
 * check_type/check_number properties and autoCheckNumber(), though the web
 * component never actually wires handlesChecksRules() into its own live
 * validation — this applies that same rule set explicitly, which is the
 * stricter, correct behaviour and the one CheckNumberSuggester/
 * StoreVendorPaymentRequest already enforce elsewhere).
 *
 * authorize() mirrors ExpenseForm::store()'s ordering exactly: it calls
 * `$this->authorize('create', Expense::class)` BEFORE `$this->validate()`,
 * so a non-admin gets 403 even when the body would otherwise fail
 * validation (e.g. no project_id) — checking here, not in the controller,
 * is what gives the same before-validation ordering.
 */
class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Expense::class);
    }

    /**
     * On update, the expense's own already-recorded check must be excluded
     * from the check_number uniqueness check (re-submitting its unchanged
     * bank/number would otherwise collide with itself) — see
     * UpdateExpenseRequest's override.
     */
    protected function excludedCheckId(): ?int
    {
        return null;
    }

    /**
     * The expense a submitted split 'id' must already belong to — null on
     * create (where no split can legitimately carry an id yet). See
     * UpdateExpenseRequest's override and the 'splits.*.id' check in
     * withValidator() below: this is what lets AppExpenseDirectory's
     * ExpenseSplitsWriter trust a submitted split id is really one of
     * THIS expense's own rows, not another tenant's.
     */
    protected function expenseIdForSplitOwnership(): ?int
    {
        return null;
    }

    /**
     * A receipt photo attached alongside JSON fields means the request is
     * multipart/form-data, which can't carry a nested `splits` array
     * natively — the app JSON-encodes it into a single field in that case.
     * Decode it back into an array before validation either way.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('splits'))) {
            $decoded = json_decode((string) $this->input('splits'), true);
            $this->merge(['splits' => is_array($decoded) ? $decoded : []]);
        }

        // A receipt photo makes this multipart/form-data, which only ever
        // carries strings — normalize "true"/"false"/"1"/"0" the same way
        // whether the body is JSON (a real bool already) or multipart.
        if ($this->has('is_material_order')) {
            $this->merge(['is_material_order' => $this->boolean('is_material_order')]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $vendorId = $this->user()?->vendor?->id;

        return [
            'amount' => ['required', 'numeric', 'regex:/^-?\d+(\.\d{1,2})?$/'],
            'date' => ['required', 'date', 'before_or_equal:today', 'after:2017-01-01'],
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'project_id' => ['nullable', 'integer'],
            // The web's own distribution selector (ExpenseForm.php:144-150)
            // exposed as its own field instead of the web's single-select
            // "D:{id}" encoding — ExpenseAccessGuard::resolveProjectSelector()
            // accepts either shape. Scoped against this tenant's own
            // distributions (DistributionScope) by
            // ExpenseAccessGuard::assertProjectAccessible(), not an `exists:`
            // rule, for the same reason as belongs_to_vendor_id below.
            'distribution_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
            'invoice' => ['nullable', 'string', 'max:255'],
            // Limited to the hub's own employed team — mirrors the web's
            // "Paid By" select (ExpenseCreate::employees()), not just any
            // user id.
            'paid_by' => ['nullable', 'integer', $this->paidByRule()],
            // 'Client', an employed teammate's user id, or 'V:{vendor_id}'
            // for a Sub/1099 vendor — mirrors the web's "Reimbursment"
            // select exactly (expenses/form.blade.php:294-312).
            'reimbursement' => ['nullable', 'string', 'max:255', $this->reimbursementRule()],
            'is_material_order' => ['nullable', 'boolean'],
            // Whitelisted against the hub vendor (or one already linked to
            // it) by ExpenseAccessGuard::resolveOwnerVendorId() — not an
            // `exists:` rule here, since that alone wouldn't enforce the
            // tenant boundary.
            'belongs_to_vendor_id' => ['nullable', 'integer'],
            // Attach to an already-existing check (create only) — scoped
            // against this tenant's own checks by
            // ExpenseAccessGuard::assertCheckAccessible(), not an `exists:`
            // rule, for the same reason as belongs_to_vendor_id above.
            'existing_check_id' => ['nullable', 'integer'],
            // "Create an expense from the transaction" (create only) — the
            // Transactions card's row action. Scoped against this tenant's
            // own still-unmatched transactions by
            // ExpenseAccessGuard::assertTransactionAccessible(), not an
            // `exists:` rule, for the same reason as existing_check_id above.
            'transaction_id' => ['nullable', 'integer'],
            // Recording a brand-new check — mirrors App\Traits\HandlesChecks'
            // handlesChecksRules() exactly (same scoping, same messages).
            'bank_account_id' => [
                'nullable', 'integer',
                Rule::exists('bank_accounts', 'id')->where('vendor_id', $vendorId),
            ],
            'check_type' => ['nullable', 'required_with:bank_account_id', 'in:Check,Transfer,Cash'],
            'check_number' => [
                'nullable', 'required_if:check_type,Check', 'integer',
                $this->checkNumberUniqueRule(),
            ],
            'splits' => ['nullable', 'array'],
            // Present only when editing an already-saved split (sent back by
            // the app so AppExpenseDirectory's ExpenseSplitsWriter updates
            // that exact row in place — receipt_items, id and distribution
            // links included — instead of deleting and recreating it).
            // Ownership (must belong to the expense being edited) is
            // enforced in withValidator() below, not an `exists:` rule.
            'splits.*.id' => ['nullable', 'integer'],
            'splits.*.amount' => ['required', 'numeric', 'regex:/^-?\d+(\.\d{1,2})?$/', 'not_in:0'],
            // Exactly one of project_id/distribution_id per split — same
            // "D:{id}" idea as the top-level selector above, as two plain
            // fields; enforced together in withValidator() below (neither
            // `required` alone would allow "either, not both, not neither").
            'splits.*.project_id' => ['nullable', 'integer'],
            'splits.*.distribution_id' => ['nullable', 'integer'],
            'splits.*.note' => ['nullable', 'string', 'max:2000'],
            // Mirrors the web split editor's own flux:radio.group exactly
            // (splits-form.blade.php:80-91) — narrower than the top-level
            // expense's own Reimbursement field above.
            'splits.*.reimbursement' => ['nullable', Rule::in(['None', 'Client'])],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
        ];
    }

    /**
     * Mirrors the web's "Paid By" select (ExpenseCreate::employees(), via
     * App\Services\Users\AppTeamDirectory::teamMembers()) — limited to the
     * hub vendor's own currently-employed team, not any arbitrary user id.
     */
    private function paidByRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $vendor = $this->user()?->vendor;

            if (! $vendor || ! $vendor->users()->employed()->whereKey((int) $value)->exists()) {
                $fail('Invalid Paid By selection.');
            }
        };
    }

    /**
     * Mirrors the web's "Reimbursment" select exactly
     * (expenses/form.blade.php:294-312): 'Client', an employed teammate's
     * user id (as a numeric string), or 'V:{vendor_id}' for a vendor
     * ExpenseCreate::reimbursment_vendors() would actually offer (Sub/1099,
     * and — via Vendor's own global VendorScope — one this tenant can see).
     */
    private function reimbursementRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '' || $value === 'Client') {
                return;
            }

            if (is_numeric($value)) {
                $vendor = $this->user()?->vendor;
                if (! $vendor || ! $vendor->users()->employed()->whereKey((int) $value)->exists()) {
                    $fail('Invalid reimbursement selection.');
                }

                return;
            }

            if (is_string($value) && str_starts_with($value, 'V:')) {
                $reimbursementVendorId = substr($value, 2);
                if (! ctype_digit($reimbursementVendorId) || ! Vendor::whereKey((int) $reimbursementVendorId)->whereIn('business_type', ['Sub', '1099'])->exists()) {
                    $fail('Invalid reimbursement selection.');
                }

                return;
            }

            $fail('Invalid reimbursement selection.');
        };
    }

    /**
     * Unique across every account under the submitted bank_account_id's own
     * bank (CheckNumberSuggester::siblingAccountIds()) — paper checks only,
     * excluding soft-deleted rows, the expense's own already-recorded check
     * (on update), AND an exact (bank_account_id, vendor_id) match: that's
     * not a collision, it's a SECOND expense against the same already-
     * recorded check, which AppExpenseDirectory's own find-or-create simply
     * reuses — mirrors ExpenseForm::store()/update()'s $existing_check
     * lookup, which is keyed the same way.
     */
    private function checkNumberUniqueRule(): \Illuminate\Validation\Rules\Unique
    {
        $bankAccountId = $this->input('bank_account_id');
        $vendorId = $this->input('vendor_id');
        $excludedCheckId = $this->excludedCheckId();

        $rule = Rule::unique('checks', 'check_number')->where(function ($query) use ($bankAccountId, $vendorId) {
            $query->where('check_type', 'Check')->whereNull('deleted_at');

            $account = $bankAccountId ? BankAccount::withoutGlobalScopes()->find($bankAccountId) : null;
            if ($account) {
                $siblingIds = app(CheckNumberSuggester::class)->siblingAccountIds($account);
                $query->whereIn('bank_account_id', $siblingIds);
            } else {
                // No bank account submitted (or an invalid one, already
                // caught by the bank_account_id rule) — fall back to this
                // one id so the uniqueness check still scopes narrowly
                // rather than matching every bank's check numbers.
                $query->where('bank_account_id', $bankAccountId);
            }

            $query->where(function ($inner) use ($bankAccountId, $vendorId) {
                $inner->where('bank_account_id', '!=', $bankAccountId)
                    ->orWhere('vendor_id', '!=', $vendorId);
            });
        });

        return $excludedCheckId ? $rule->ignore($excludedCheckId) : $rule;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'Amount format is incorrect. Use e.g. 2145.36, no commas and at most two decimal digits.',
            'date.before_or_equal' => 'Date cannot be in the future.',
            'date.after' => 'Date cannot be before 2017-01-01.',
            'receipt.mimes' => 'Receipt must be a PDF, JPG, or PNG.',
            'receipt.max' => 'Receipt must be less than 20MB.',
            'check_number.unique' => 'Check already recorded.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $splits = $this->input('splits', []);
            $hasSplits = is_array($splits) && count($splits) > 0;

            if ($hasSplits) {
                $sum = round(array_sum(array_map(fn ($split) => (float) ($split['amount'] ?? 0), $splits)), 2);
                $amount = round((float) $this->input('amount', 0), 2);

                if (abs($amount - $sum) >= 0.01) {
                    $validator->errors()->add('splits', 'Split amounts must add up to the expense amount.');
                }

                $this->validateSplitSelectors($validator, $splits);

                return;
            }

            if ($this->filled('project_id') && $this->filled('distribution_id')) {
                $validator->errors()->add('project_id', 'Choose a project or a distribution, not both.');

                return;
            }

            if (! $this->filled('project_id') && ! $this->filled('distribution_id')) {
                $validator->errors()->add('project_id', 'Project is required unless the expense is split.');
            }
        });
    }

    /**
     * Each split needs exactly one of project_id/distribution_id, and any
     * split 'id' the request sends back must already belong to the expense
     * being edited — AppExpenseDirectory's ExpenseSplitsWriter trusts a
     * submitted id enough to update that exact row in place, so this is
     * what keeps a tampered id from overwriting a DIFFERENT expense's split.
     *
     * @param  array<int, array<string, mixed>>  $splits
     */
    private function validateSplitSelectors(Validator $validator, array $splits): void
    {
        $expenseId = $this->expenseIdForSplitOwnership();

        foreach ($splits as $index => $split) {
            $hasProject = ! empty($split['project_id']);
            $hasDistribution = ! empty($split['distribution_id']);

            if ($hasProject === $hasDistribution) {
                $validator->errors()->add("splits.{$index}.project_id", 'Choose a project or a distribution for this split.');
            }

            if (! empty($split['id'])) {
                $belongsToExpense = $expenseId !== null
                    && ExpenseSplits::withoutGlobalScopes()->whereKey($split['id'])->where('expense_id', $expenseId)->exists();

                if (! $belongsToExpense) {
                    $validator->errors()->add("splits.{$index}.id", 'Invalid split selected.');
                }
            }
        }
    }
}
