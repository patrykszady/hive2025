<?php

/**
 * Shared fixtures for the payment-processor matching tests
 * (ProcessorAwareVendorMatchingTest, ProcessorMatchRuleGuardTest):
 * the exact Plaid payloads of the three Paddle transactions read from
 * production on 2026-11-10 (only the all-null location block and logo URLs
 * are left out), two synthetic payloads for a processor Plaid alone names,
 * and the rows the sync needs around them. require_once this file rather
 * than declaring these names twice.
 */

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorTransaction;

if (! function_exists('plaidDataforseoFull')) {
    /** Transaction 30216: "PADDLE.NET* DATAFORSEO", $50, 2026-11-02 — Plaid names the merchant, not the processor. */
    function plaidDataforseoFull(): array
    {
        return [
            'date' => '2026-11-02', 'name' => 'PADDLE.NET* DATAFORSEO', 'amount' => 50, 'pending' => false, 'website' => null,
            'category' => ['Service'], 'datetime' => null, 'account_id' => 'g0j8wjEJRxfPXXxpaN8auOg7gB3Ly4tMZpE4P',
            'category_id' => '18000000', 'check_number' => null,
            'payment_meta' => ['payee' => null, 'payer' => null, 'ppd_id' => null, 'reason' => null, 'by_order_of' => null, 'payment_method' => null, 'reference_number' => null, 'payment_processor' => null],
            'account_owner' => '0616', 'merchant_name' => 'Dataforseo',
            'counterparties' => [
                ['name' => 'Dataforseo', 'type' => 'merchant', 'website' => null, 'entity_id' => null, 'phone_number' => null, 'confidence_level' => 'LOW'],
            ],
            'transaction_id' => 'qngxmgMJKVCLooZKeRZEtE5DBKRB6EHD7wzBz', 'authorized_date' => null, 'payment_channel' => 'online',
            'transaction_code' => 'purchase', 'transaction_type' => 'place', 'iso_currency_code' => 'USD', 'merchant_entity_id' => null,
            'authorized_datetime' => null, 'original_description' => 'PADDLE.NET* DATAFORSEO', 'merchant_category_code' => '5817',
            'pending_transaction_id' => null, 'unofficial_currency_code' => null,
            'personal_finance_category' => ['primary' => 'GENERAL_SERVICES', 'version' => 'v2', 'detailed' => 'GENERAL_SERVICES_OTHER_GENERAL_SERVICES', 'confidence_level' => 'HIGH'],
        ];
    }

    /** Transaction 29633: Citi's truncated form, $50, 2026-09-04 — Plaid wrongly calls the processor the merchant. */
    function plaidDataforseoTruncated(): array
    {
        return [
            'date' => '2026-09-09', 'name' => 'DEBIT PURCHASE Sep 04 4849 PADDLE.NET* DATAFORSE 26248', 'amount' => 50, 'pending' => false, 'website' => null,
            'category' => ['Service'], 'datetime' => null, 'account_id' => 'eRNx665amRua1M0eyoY6tXNvYevKRvFeaoV1O',
            'category_id' => '18000000', 'check_number' => null,
            'payment_meta' => ['payee' => null, 'payer' => null, 'ppd_id' => null, 'reason' => null, 'by_order_of' => null, 'payment_method' => null, 'reference_number' => null, 'payment_processor' => null],
            'account_owner' => null, 'merchant_name' => 'Paddle',
            'counterparties' => [
                ['name' => 'Paddle', 'type' => 'merchant', 'website' => null, 'entity_id' => null, 'phone_number' => null, 'confidence_level' => 'LOW'],
            ],
            'transaction_id' => '5ZDr77kL8ZsqmYRbaPmrt4yj9PLbaPUb13myL', 'authorized_date' => '2026-09-04', 'payment_channel' => 'online',
            'transaction_code' => null, 'transaction_type' => 'place', 'iso_currency_code' => 'USD', 'merchant_entity_id' => null,
            'authorized_datetime' => null, 'original_description' => 'DEBIT PURCHASE Sep 04 4849 PADDLE.NET* DATAFORSE New York NY 26248',
            'merchant_category_code' => null, 'pending_transaction_id' => 'OkNQaa6XmkFKJn0QLBJwTMRKnJO5vYT45LZ6E', 'unofficial_currency_code' => null,
            'personal_finance_category' => ['primary' => 'GENERAL_SERVICES', 'version' => 'v2', 'detailed' => 'GENERAL_SERVICES_OTHER_GENERAL_SERVICES', 'confidence_level' => 'HIGH'],
        ];
    }

    /** Transaction 20442: the legitimate 2023 Laravel Daily charge, bare "PADDLE.NET", $129, paid through PayPal. */
    function plaidLaravelDailyBare(): array
    {
        return [
            'date' => '2023-09-04', 'name' => 'PADDLE.NET', 'amount' => 129, 'pending' => false, 'website' => null,
            'category' => ['Transfer', 'Third Party', 'PayPal'], 'datetime' => null, 'account_id' => 'RaKJvKZqQpt5ddnAkYV7u77rEBjZP6TyzLpoP',
            'category_id' => '21010004', 'check_number' => null,
            'payment_meta' => ['payee' => null, 'payer' => null, 'ppd_id' => null, 'reason' => null, 'by_order_of' => null, 'payment_method' => null, 'reference_number' => null, 'payment_processor' => 'PayPal'],
            'account_owner' => '3134', 'merchant_name' => 'Paddle',
            'counterparties' => [
                ['name' => 'PayPal', 'type' => 'payment_app', 'website' => 'paypal.com', 'entity_id' => null, 'phone_number' => null, 'confidence_level' => 'UNKNOWN'],
                ['name' => 'Paddle', 'type' => 'merchant', 'website' => null, 'entity_id' => null, 'phone_number' => null, 'confidence_level' => 'UNKNOWN'],
            ],
            'transaction_id' => 'dkj9MjpR7riVRRdY9aansvazENJd8wUQVDpK4', 'authorized_date' => null, 'payment_channel' => 'online',
            'transaction_code' => null, 'transaction_type' => 'special', 'iso_currency_code' => 'USD', 'merchant_entity_id' => null,
            'authorized_datetime' => null, 'pending_transaction_id' => null, 'unofficial_currency_code' => null,
            'personal_finance_category' => ['primary' => 'TRANSFER_OUT', 'detailed' => 'TRANSFER_OUT_ACCOUNT_TRANSFER', 'confidence_level' => 'LOW'],
        ];
    }

    /**
     * A GitHub charge paid through PayPal the way production holds them:
     * the cleaned descriptor names the merchant, only Plaid's payload
     * (payment_processor, the payment_app counterparty, the raw
     * original_description) says PayPal was involved.
     */
    function plaidPayPalOnly(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-10-20', 'name' => 'GITHUB INC', 'amount' => 4, 'pending' => false,
            'payment_meta' => ['payment_processor' => 'PayPal'],
            'merchant_name' => 'GitHub',
            'counterparties' => [
                ['name' => 'PayPal', 'type' => 'payment_app', 'website' => 'paypal.com', 'confidence_level' => 'VERY_HIGH'],
                ['name' => 'GitHub', 'type' => 'merchant', 'website' => 'github.com', 'confidence_level' => 'HIGH'],
            ],
            'payment_channel' => 'online', 'original_description' => 'PAYPAL *GITHUB INC',
        ], $overrides);
    }

    /** A charge where only Plaid names the processor and it has no curated prefix of its own. */
    function plaidBraintreeAcme(): array
    {
        return [
            'date' => '2026-10-22', 'name' => 'BRAINTREE* ACME TOOLS 1234', 'amount' => 88, 'pending' => false,
            'payment_meta' => ['payment_processor' => 'Braintree'],
            'merchant_name' => 'Acme Tools',
            'counterparties' => [
                ['name' => 'Braintree', 'type' => 'payment_app', 'confidence_level' => 'HIGH'],
                ['name' => 'Acme Tools', 'type' => 'merchant', 'confidence_level' => 'HIGH'],
            ],
            'payment_channel' => 'online', 'original_description' => 'BRAINTREE* ACME TOOLS 1234',
        ];
    }

    /** Microsoft 365 as Plaid reports it: Microsoft is processor, merchant counterparty and merchant name at once. */
    function plaidSelfBilledMicrosoft(): array
    {
        return [
            'date' => '2026-10-13', 'name' => 'E0100KW0UY', 'amount' => 12.5, 'pending' => false,
            'payment_meta' => ['payment_processor' => 'Microsoft'],
            'merchant_name' => 'Microsoft',
            'counterparties' => [
                ['name' => 'Microsoft', 'type' => 'merchant', 'website' => 'microsoft.com', 'confidence_level' => 'VERY_HIGH'],
            ],
            'payment_channel' => 'online', 'original_description' => 'E0100KW0UY',
        ];
    }

    /**
     * A company with an Admin user and one bank account the sync will look
     * at. $companyId pins the company's id (the app API's tests use vendor
     * id 1, GS Construction, like the rest of that suite).
     *
     * @return array{company: Vendor, user: User, account: BankAccount}
     */
    function processorFixtureCompany(?int $companyId = null): array
    {
        $company = ($companyId !== null ? Vendor::query()->find($companyId) : null)
            ?? Vendor::factory()->create(array_filter(['id' => $companyId, 'business_name' => 'Processor Fixture Co', 'business_type' => 'Sub']));
        $company->forceFill(['registration' => ['registered' => true]])->save();

        $user = User::factory()->create(['primary_vendor_id' => $company->id]);
        $user->vendors()->attach($company->id, ['role_id' => 1]);

        $bank = Bank::create(['name' => 'Fixture Bank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_processor_'.uniqid()]);
        $account = BankAccount::create([
            'vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4849',
            'plaid_account_id' => 'acc_processor_'.uniqid(), 'type' => 'credit',
        ]);

        return compact('company', 'user', 'account');
    }

    /** A Retail vendor with no pinned address, so the location gate stays out of the way. */
    function processorFixtureRetailVendor(string $name): Vendor
    {
        return Vendor::factory()->create([
            'business_name' => $name, 'business_type' => 'Retail',
            'address' => null, 'address_2' => null, 'city' => null, 'state' => null, 'zip_code' => null,
        ]);
    }

    /** A stored transaction built from a Plaid payload, as the sync stores it (name → description, merchant_name, details). */
    function processorFixtureTransaction(BankAccount $account, array $details, array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'transaction_date' => $details['date'],
            'amount' => $details['amount'],
            'bank_account_id' => $account->id,
            'vendor_id' => null,
            'plaid_merchant_name' => $details['merchant_name'] ?? null,
            'plaid_merchant_description' => $details['name'],
            'details' => $details,
        ], $overrides));
    }

    /** Rule 280 as production holds it: desc "PADDLE.NET", options "/i", vendor Laravel Daily. */
    function paddleRule280(Vendor $laravelDaily): VendorTransaction
    {
        return VendorTransaction::create([
            'vendor_id' => $laravelDaily->id, 'deposit_check' => null, 'amount_sign' => null, 'plaid_inst_id' => null,
            'desc' => 'PADDLE.NET', 'options' => json_encode('/i'),
        ]);
    }
}
