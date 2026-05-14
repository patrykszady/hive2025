<?php

namespace App\Jobs;

use App\Models\ExpenseReceipts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Re-dispatch image scrapes for material-order receipt items that are still
 * missing image_url after their initial scrape (API timeouts, bad queries, etc.).
 *
 * Replaces an inline Schedule::call closure so the iteration runs on a Horizon
 * worker rather than the schedule:run process.
 */
class DispatchIncompleteReceiptImageScrapesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('background');
    }

    public function handle(): void
    {
        $receipts = ExpenseReceipts::where('is_material_order', true)
            ->where('created_at', '>=', now()->subDays(14))
            ->get()
            ->filter(function ($receipt) {
                $items = $receipt->receipt_items['items'] ?? [];
                foreach ($items as $item) {
                    if (! empty($item['Description']) && empty($item['image_url'])) {
                        return true;
                    }
                }

                return false;
            });

        foreach ($receipts as $receipt) {
            ScrapeReceiptItemImagesV2::dispatch($receipt);
        }
    }
}
