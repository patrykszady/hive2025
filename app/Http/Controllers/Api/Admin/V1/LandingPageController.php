<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Management API for ss-systems' Livewire\Admin\LandingPages screen —
 * hand-created `/lp/` campaign pages for ads. Same index/services/store/
 * publish/unpublish/destroy contract as gs.construction's and
 * dawnsellshomes.com's LandingPageController (ported from
 * dawnsellshomes' version), with everything Projects-shaped removed
 * rather than relaxed:
 *
 *  - no proof requirement of any kind — Hive has no home-improvement
 *    Projects/proof domain, so content is assembled from small static
 *    templates in buildContent() below, and publish() never blocks on it.
 *  - no sitemap regeneration after publish/unpublish — this app's
 *    sitemap (App\Support\MarketingSitemap) is a cached in-process read
 *    built from route definitions + config('marketing.areas'), and
 *    landing pages are deliberately never added to it (see LandingPage's
 *    class docblock) — nothing to regenerate.
 */
class LandingPageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));

        $paginator = LandingPage::query()->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (LandingPage $page) => $page->toApiArray())->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /** The fixed campaign-type catalogue the generate form offers — see LandingPage::CAMPAIGN_TYPES. */
    public function services(): JsonResponse
    {
        return response()->json(['data' => LandingPage::CAMPAIGN_TYPES]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:255'],
            'modifier' => ['nullable', 'string', 'max:100'],
        ]);

        $service = $data['service'];
        $city = trim($data['city']);
        $modifier = filled($data['modifier'] ?? null) ? trim($data['modifier']) : null;

        $slug = LandingPage::generateUniqueSlug(trim(($modifier ? $modifier.' ' : '').$service.' '.$city));

        $page = LandingPage::create(array_merge(
            $this->buildContent($service, $city, $modifier),
            [
                'slug' => $slug,
                'service' => $service,
                'city' => $city,
                'modifier' => $modifier,
                'source' => 'manual',
                'status' => LandingPage::STATUS_DRAFT,
            ]
        ));

        return response()->json(['data' => $page->toApiArray()], 201);
    }

    public function publish(int $landingPage): JsonResponse
    {
        $page = LandingPage::findOrFail($landingPage);

        // No proof gate here — Hive has no home-improvement Projects/proof
        // domain at all; every page is hand-created and already reviewed by
        // an admin before Publish is clicked.
        $page->update(['status' => LandingPage::STATUS_PUBLISHED, 'published_at' => now()]);

        return response()->json(['data' => $page->fresh()->toApiArray()]);
    }

    public function unpublish(int $landingPage): JsonResponse
    {
        $page = LandingPage::findOrFail($landingPage);

        $page->update(['status' => LandingPage::STATUS_DRAFT, 'published_at' => null]);

        return response()->json(['data' => $page->fresh()->toApiArray()]);
    }

    public function destroy(int $landingPage): Response
    {
        LandingPage::findOrFail($landingPage)->delete();

        return response()->noContent();
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
