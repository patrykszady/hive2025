<?php

namespace App\Console\Commands;

use App\Http\Controllers\VendorDocsController;
use Illuminate\Console\Command;

class FetchInsuranceMailbox extends Command
{
    protected $signature = 'mailbox:fetch-insurance';

    protected $description = 'Fetch and process messages from the certificates/insurance mailbox';

    public function handle(VendorDocsController $controller): int
    {
        $this->info('Fetching messages from insurance mailbox...');

        $controller->fetchMessagesFromInsuranceMailbox();

        $this->info('Done.');

        return self::SUCCESS;
    }
}
