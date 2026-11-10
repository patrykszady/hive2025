<?php

namespace App\Http\Controllers;

use SsSystems\Platform\Seo\Faq\Contracts\FaqCatalog;
use SsSystems\Platform\Seo\Faq\Http\Concerns\BuildsFaqPageFeed;

/**
 * GET /geo/answers.json — the Q&A feed for AI engines as FAQPage JSON-LD,
 * the one gs.construction and jpeterson-design serve through the kit's
 * SsSystems\Platform\Seo\Faq\Http\Concerns\BuildsFaqPageFeed (2026-11-10).
 * Source: config/geo-answers.php through App\Support\Seo\Faq\
 * ConfigFaqCatalog (bound in AppServiceProvider). The envelope, the hour's
 * cache and the headers are the trait's; this class supplies the
 * config('geo-answers.meta.*') reads whose fallback is this site's own,
 * and roots the feed's and the organization's URL on the marketing host
 * like every other feed here (the trait's default is url(), i.e. APP_URL,
 * which on this split-host app is not the public site).
 */
class GeoAnswersController extends Controller
{
    use BuildsFaqPageFeed;

    public function __construct(private readonly FaqCatalog $catalog) {}

    protected function buildFaqPagePayload(): array
    {
        return $this->faqPageEnvelope(
            $this->faqCatalog(),
            marketing_url('geo/answers.json'),
            marketing_url().'/',
            now()->toIso8601String(),
        );
    }

    protected function faqCatalog(): FaqCatalog
    {
        return $this->catalog;
    }

    protected function faqBusinessName(): string
    {
        return (string) config('geo-answers.meta.business', config('brand.name', 'Hive Contractors'));
    }

    protected function faqPhone(): ?string
    {
        $phone = trim((string) config('geo-answers.meta.phone', ''));

        return $phone !== '' ? $phone : null;
    }

    /** The support mailbox every public page's mailto: points at. */
    protected function faqEmail(): ?string
    {
        $email = trim((string) config('mail.from.address', ''));

        return $email !== '' ? $email : null;
    }

    protected function faqAreaServed(): ?string
    {
        return config('geo-answers.meta.service_area');
    }
}
