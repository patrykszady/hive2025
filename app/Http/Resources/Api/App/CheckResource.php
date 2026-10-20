<?php

namespace App\Http\Resources\Api\App;

use App\Models\CheckImage;
use App\Models\Transaction;
use App\Services\Checks\AppCheckBreakdown;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * A check as the app's Checks tab shows it. With withDetail() it adds the
 * images and the expenses/timesheets breakdown the detail screen needs
 * beyond the list row — see CheckController::show() and
 * App\Services\Checks\AppCheckBreakdown.
 *
 * `status`/`status_color` are the same Check::status()/statusColor()
 * accessors the web's checks table badge reads (Complete/Missing
 * Transactions/No Transactions), not re-derived here.
 *
 * @mixin \App\Models\Check
 */
class CheckResource extends JsonResource
{
    protected bool $detail = false;

    public function withDetail(): static
    {
        $this->detail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'date' => $this->date?->toDateString(),
            'check_number' => $this->check_number,
            'check_type' => $this->check_type,
            'payment_label' => $this->payment_label,
            'bank' => $this->resource->bank_account?->getNameAndType(),
            'payee' => $this->payeePayload(),
            'status' => [
                'label' => $this->status,
                'color' => $this->statusColor,
            ],
            'web_path' => '/checks/'.$this->id,
        ];

        if (! $this->detail) {
            return $row;
        }

        $breakdown = app(AppCheckBreakdown::class);

        return $row + [
            'images' => $this->imagesArray(),
            // The bank transaction(s) that cleared this check — mirrors the
            // web's "Transactions" card (x-transactions.list_card), shown
            // whenever $check->transactions->isNotEmpty().
            'transactions' => $this->transactionsArray(),
            'expenses' => $breakdown->expenses($this->resource),
            // The payee's own display name for the "{name}'s Timesheets"
            // card heading — mirrors CheckShow's
            // $check->user->nickname ?: $check->user->first_name.
            'payee_first_name' => $this->resource->user
                ? ($this->resource->user->nickname ?: $this->resource->user->first_name)
                : null,
            'timesheets' => $breakdown->timesheets($this->resource),
            'employee_paid_timesheets' => $breakdown->employeePaidTimesheets($this->resource),
            // The edit form's own fields — mirrors CheckForm::setCheck().
            // vendor_id/user_id/bank_account_id are the raw ids (not the
            // derived payee/bank labels above) so the app's edit screen can
            // prefill its selects the same way the web's modal does.
            'editable' => [
                'bank_account_id' => $this->bank_account_id,
                'check_type' => $this->check_type,
                'check_number' => $this->check_number,
                'vendor_id' => $this->vendor_id,
                'user_id' => $this->user_id,
                'date' => $this->date?->toDateString(),
                // CheckForm's payee select only ever shows for a check
                // written to a vendor (never a payroll/employee check) —
                // same condition as the web modal's @if.
                'payee_editable' => (bool) ($this->vendor_id && ! $this->user_id),
                // Fields disable on the web once the bank has matched a
                // transaction to this check for its full amount — same
                // CheckForm::setCheck() condition.
                'locked' => (bool) ($this->resource->transactions->sum('amount') == $this->resource->amount),
                'can_delete' => $request->user()->can('delete', $this->resource),
            ],
        ];
    }

    /**
     * The payee's label plus where the app can push to natively (a vendor or
     * a user screen) — same precedence Check::payeeRoute() builds the web's
     * payeeUrl() from.
     *
     * @return array{label: ?string, web_path: ?string, id: ?int, type: ?string}
     */
    private function payeePayload(): array
    {
        $route = $this->resource->payeeRoute();

        return [
            'label' => $this->owner,
            'web_path' => $this->webPathFor($this->payeeUrl()),
            'id' => $route['id'] ?? null,
            'type' => $route['type'] ?? null,
        ];
    }

    /**
     * The web's payeeUrl()/owner() point at vendors.show or users.show —
     * kept for the "Open in Hive" link (payeePayload() above now also gives
     * the app its own id/type to push to natively).
     */
    private function webPathFor(?string $absoluteOrRelativeRoute): ?string
    {
        if (! $absoluteOrRelativeRoute) {
            return null;
        }

        return '/'.ltrim(parse_url($absoluteOrRelativeRoute, PHP_URL_PATH) ?? '', '/');
    }

    /**
     * The bank transaction(s) matched to this check — mirrors
     * x-transactions.list_card's Amount/Date/Bank/Account columns (the
     * same "Account" shape PaymentResource's transactionArray() uses).
     *
     * @return list<array{id: int, amount: float, date: ?string, bank: ?string, account: ?string}>
     */
    private function transactionsArray(): array
    {
        return $this->resource->transactions
            ->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'amount' => (float) $transaction->amount,
                'date' => $transaction->transaction_date?->toDateString(),
                'bank' => $transaction->bank_account?->bank?->name,
                'account' => $transaction->owner !== null
                    ? (string) $transaction->owner
                    : ($transaction->bank_account?->account_number !== null ? (string) $transaction->bank_account->account_number : null),
            ])
            ->values()
            ->all();
    }

    /**
     * Scanned check images, each as a session-less signed URL (see
     * routes/web.php's checks.image.signed, mirroring
     * projects.timelapse.frame.signed) so the app can show them without a
     * web session or cookie.
     *
     * @return list<array{id: int, url: string, check_date: ?string, statement_url: ?string}>
     */
    private function imagesArray(): array
    {
        return $this->resource->checkImages()
            ->orderBy('check_date')
            ->get()
            ->filter(fn (CheckImage $image) => (bool) $image->image_filename)
            ->map(fn (CheckImage $image) => [
                'id' => $image->id,
                'url' => URL::temporarySignedRoute(
                    'checks.image.signed',
                    now()->addHours(24),
                    ['image' => $image->id]
                ),
                'check_date' => $image->check_date?->toDateString(),
                // The web's "View Full Statement" icon button
                // (checks/show.blade.php:251-252) — only the FIRST image's
                // statement, same as the web reading $check_images->first().
                'statement_url' => $image->statement_filename
                    ? URL::temporarySignedRoute(
                        'checks.statement.signed',
                        now()->addHours(24),
                        ['filename' => $image->statement_filename]
                    )
                    : null,
            ])
            ->values()
            ->all();
    }
}
