<?php

namespace App\Console\Commands;

use App\Services\WaiverScanIngest;
use Illuminate\Console\Command;

class FetchWaiversMailbox extends Command
{
    protected $signature = 'mailbox:fetch-waivers';

    protected $description = 'Poll waivers@hive.contractors for returned wet-signed lien waiver / GCSS scans and match them by barcode.';

    public function handle(WaiverScanIngest $ingest): int
    {
        $stats = $ingest->processInbox();

        $this->info(sprintf(
            'Processed %d message(s): %d document(s) matched, %d error(s), %d skipped (no usable attachment).',
            $stats['messages'],
            $stats['matched'],
            $stats['errors'],
            $stats['skipped'],
        ));

        return self::SUCCESS;
    }
}
