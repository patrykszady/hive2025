<?php

namespace App\Jobs;

use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class UpdateVendorSearchIndex implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $vendorId;

    /**
     * Create a new job instance.
     */
    public function __construct($vendorId)
    {
        $this->vendorId = $vendorId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $vendor = Vendor::find($this->vendorId);

        if (!$vendor) {
            return;
        }

        // Calculate YTD expense sum for this vendor
        $ytdSum = DB::table('expenses')
            ->where('vendor_id', $this->vendorId)
            ->where('created_at', '>=', today()->subYear())
            ->whereNull('deleted_at')
            ->sum('amount');

        // Set the calculated sum as an attribute before indexing
        $vendor->setAttribute('ytd_expense_sum', $ytdSum);
        $vendor->searchable();
    }
}
