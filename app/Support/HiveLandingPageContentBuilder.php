<?php

namespace App\Support;

use App\Models\LandingPage;
use Illuminate\Support\Str;
use SsSystems\Platform\Pages\Landing\Contracts\LandingPageContentBuilder;

/**
 * The adapter SsSystems\Platform\Pages\Landing\Http\Concerns\
 * ServesLandingPages is injected with on this app — Hive's analogue of
 * gs.construction's LandingPageContentGenerator / dawnsellshomes'
 * buildContent(). No proof requirement, no permit/pricing lookups: just
 * campaign type + city (+ optional modifier) turned into a publishable
 * starting draft an admin edits from there. Ported verbatim from
 * Api\Admin\V1\LandingPageController's own former private buildContent()/
 * fit() — no behavior change, just relocated so the shared trait can
 * inject it (docs/CONSOLIDATION-PLAN.md, Kit 0.14.0).
 */
class HiveLandingPageContentBuilder implements LandingPageContentBuilder
{
    /** The fixed campaign-type catalogue the generate form offers — see LandingPage::CAMPAIGN_TYPES. */
    public function services(): array
    {
        return LandingPage::CAMPAIGN_TYPES;
    }

    public function build(array $input): ?array
    {
        return $this->buildContent($input['service'], $input['city'], $input['modifier']);
    }

    /** Hive has no home-improvement Projects/proof domain at all — every page is hand-created and already reviewed before Publish is clicked. */
    public function requiresProofToPublish(): bool
    {
        return false;
    }

    /**
     * Lightweight, static templated copy — Hive's analogue of
     * gs.construction's LandingPageContentGenerator / dawnsellshomes'
     * buildContent(). No proof requirement, no permit/pricing lookups:
     * just campaign type + city (+ optional modifier) turned into a
     * publishable starting draft an admin edits from there.
     *
     * @return array<string, mixed>
     */
    private function buildContent(string $service, string $city, ?string $modifier): array
    {
        $label = LandingPage::CAMPAIGN_TYPES[$service] ?? Str::of($service)->replace('-', ' ')->title()->toString();
        $brand = config('app.name', 'Hive Contractors');

        $h1 = trim(($modifier ? "{$modifier} " : '').$label.' for '.$city.' Contractors');

        $title = $this->fit(trim(($modifier ? "{$modifier} " : '').$label).' — '.$brand, 60);

        $meta = $this->fit(
            trim(strtolower(($modifier ? "{$modifier} " : '').$label))." for contractors in {$city}. {$brand} — "
            .'the CRM built by contractors, for contractors. No credit card required to start.',
            158
        );

        $intro = "Looking into {$label} for your {$city} contracting business? {$brand} brings your finances, "
            .'communication, and project planning under one roof — built by contractors who ran into the same '
            .'headaches you did.';

        $sections = [
            [
                'heading' => "What {$label} looks like in {$brand}",
                'body' => "Every contractor's paperwork piles up a little differently — receipts, texts, schedules, "
                    ."and change orders all compete for the same evening hours. {$label} is one of the pieces "
                    ."{$brand} automates so you get that time back, not another tool to babysit.",
            ],
            [
                'heading' => "Why contractors in {$city} switch to {$brand}",
                'body' => "{$brand} is made by contractors, for contractors — every feature exists because we "
                    .'needed it on a real job site, not because it looked good in a demo. Free for subcontractors '
                    .'under $200K in revenue, and free up to $400K for subs whose general contractor is already '
                    .'signed up.',
            ],
        ];

        $faq = [
            [
                'q' => "Does {$brand} work for contractors in {$city}?",
                'a' => "Yes — {$brand} runs anywhere in the US. Create your Hive below and you'll be set up in "
                    .'minutes, no credit card required.',
            ],
        ];

        return [
            'title' => $title,
            'h1' => $h1,
            'meta_description' => $meta,
            'intro' => $intro,
            'sections' => $sections,
            'faq' => $faq,
        ];
    }

    private function fit(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space ? mb_substr($cut, 0, $space) : $cut, ' ,.-');
    }
}
