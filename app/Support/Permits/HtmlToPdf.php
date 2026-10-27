<?php

namespace App\Support\Permits;

use Spatie\Browsershot\Browsershot;

/**
 * A full page of HTML to a Letter-size PDF, the way the estimate and
 * reimbursement documents are rendered (headless Chrome via Browsershot),
 * but with no browser header/footer and no outer margins: the page styles
 * its own 1-inch margins. Its own small class so a test can swap it out —
 * Chrome does not run in the test sandbox.
 */
class HtmlToPdf
{
    public function pdf(string $html): string
    {
        return Chrome::configure(Browsershot::html($html))
            ->timeout(90)
            ->waitUntilNetworkIdle()
            ->format('Letter')
            ->showBackground()
            ->margins(0, 0, 0, 0)
            ->pdf();
    }
}
