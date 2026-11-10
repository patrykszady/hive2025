<?php

namespace App\Support\Seo\AiFeed;

use App\Support\Citations\ListingPayload;
use App\Support\MarketingPageList;
use SsSystems\Platform\Seo\AiFeed\Contracts\BusinessIdentity;

/**
 * This site's `business` block for /ai-feed.json — the same configured
 * facts App\Support\Citations\ListingPayload hands the business
 * directories, so the feed and every listing say one thing: the name from
 * config/brand.php, the marketing host, the support mailbox every public
 * page's mailto: points at, the mailing address from config/app.php and
 * the saved social profiles. There is no legal name, founding year or
 * public phone to publish (ListingPayload's own restraint; config/brand.php
 * keeps `phone`/`address` null on purpose, so `AiFeedBuilder`'s
 * `SiteIdentity::expectedPhone()` read leaves `business.phone` out), and
 * nothing here invents one.
 *
 * `extra()` carries what a software product has that a contractor's
 * `business` block does not: the home page's own description and
 * headline, who it is for and what it costs in the FAQ page's own words,
 * and the team's Chicago-area base (config/geo-answers.php).
 */
final class HiveBusinessIdentity implements BusinessIdentity
{
    public function name(): string
    {
        return (string) config('brand.name', 'Hive Contractors');
    }

    public function legalName(): ?string
    {
        return null;
    }

    public function url(): string
    {
        return marketing_url();
    }

    public function email(): ?string
    {
        $email = trim((string) config('mail.from.address', ''));

        return $email !== '' ? $email : null;
    }

    public function founded(): ?string
    {
        return null;
    }

    /** The languages the site is published in — config/locales.php's own labels, English first. */
    public function languages(): array
    {
        return array_values(array_filter(array_map(
            static fn (array $locale): string => (string) ($locale['name'] ?? ''),
            (array) config('locales.supported', []),
        )));
    }

    public function addressLocality(): ?string
    {
        return $this->address()['city'] ?: null;
    }

    public function addressRegion(): ?string
    {
        return $this->address()['state'] ?: null;
    }

    public function addressCountry(): string
    {
        return $this->address()['country'] ?: 'US';
    }

    public function social(): array
    {
        return array_filter(
            (array) (ListingPayload::make()['social'] ?? []),
            static fn (mixed $url): bool => is_string($url) && $url !== '',
        );
    }

    public function extra(): array
    {
        return array_filter([
            'description' => MarketingPageList::homeDescription(),
            // The home page's own <h1>.
            'headline' => 'The CRM built for small contractors',
            // The FAQ page's "Who is Hive Contractors for?" and "How much
            // does Hive cost?", shortened — never a figure the site does
            // not state.
            'audience' => 'Small contractors, subcontractors, and growing trades businesses',
            'pricing' => 'Free for subcontractors under $200K in annual revenue; free up to $400K for subs whose general contractor is signed up for Hive; larger plans by contacting us.',
            'team_base' => config('geo-answers.meta.base'),
            'mailing_address' => trim((string) config('app.physical_address', '')) ?: null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return array{street: string, city: string, state: string, state_name: string, zip: string, country: string} */
    private function address(): array
    {
        return ListingPayload::parseAddress((string) config('app.physical_address', ''));
    }
}
