<?php

namespace App\Jobs;

use App\Http\Controllers\CompanyEmailController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAutoReceiptMailboxJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public function __construct(public int $companyEmailId)
    {
        $this->onQueue('auto-receipts');
    }

    public function handle(CompanyEmailController $controller): void
    {
        $controller->fetchAutoReceipts($this->companyEmailId);
    }

    public function uniqueId(): string
    {
        return 'auto-receipts-company-email-' . $this->companyEmailId;
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['auto-receipts', 'company-email:' . $this->companyEmailId];
    }
}
