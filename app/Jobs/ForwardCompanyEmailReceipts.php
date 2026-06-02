<?php

namespace App\Jobs;

use App\Http\Controllers\CompanyEmailController;
use App\Models\CompanyEmail;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ForwardCompanyEmailReceipts implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public int $companyEmailId)
    {
        $this->onQueue('background');
    }

    public function handle(CompanyEmailController $controller): void
    {
        $companyEmail = CompanyEmail::withoutGlobalScopes()
            ->with(['receipts' => function ($query) {
                $query->whereNotNull('from_address')->where('from_address', '!=', '');
            }, 'vendor'])
            ->whereNotNull('grant_id')
            ->find($this->companyEmailId);

        if (! $companyEmail) {
            return;
        }

        $messageLimitDate = Carbon::now()->subDays(config('nylas.message_limit_days', 30));
        $controller->processCompanyEmailForwarding($companyEmail, $messageLimitDate);
    }

    public function uniqueId(): string
    {
        return 'forward-receipts-company-email-' . $this->companyEmailId;
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['forward-receipts', 'company-email:' . $this->companyEmailId];
    }
}
