<?php

namespace App\Http\Controllers\Api\Admin\V1;

use SsSystems\Platform\Http\Admin\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\PageSeoOverride;
use App\Support\PageSeoOverrides;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ss-systems' Services screen, over hive's 9 top-level marketing feature
 * areas (config('marketing.areas'): finances, estimates, clients, vendors,
 * planning, team, communication, photos, automation) — the same fixed-list
 * shape dawnsellshomes.com uses for its sell/buy/property-management pages
 * (tests/Feature/ServiceListFixedListSiteTest.php there, the reference this
 * mirrors): declares 'services' alone, no 'service-content' (no drafted
 * page copy, no per-section switches — ServiceForm::hasContentSections()
 * stays false and hides that whole card), no 'projects'/'landing-pages'
 * (projects_count is always 0, is_landing_page always true).
 *
 * Each area IS one of the Pages screen's own `type: area` rows (its
 * welcome.{areaKey} route) — update() edits the SAME
 * App\Models\PageSeoOverride row that page owns rather than a second copy
 * of the override logic, exactly like dawnsellshomes' ServiceController
 * forwarding name/blurb onto its PageController@update: "name" here IS
 * that page's title override, "blurb" IS its meta_description override.
 * Renaming a service through this screen therefore also changes what the
 * Pages screen shows as that page's title — intentional, since a "service"
 * and its area's own top-level page are the same thing.
 *
 * No area can be added or removed here (config('marketing.areas') is code,
 * not data) — store()/destroy() refuse 422 WITH a field-level `errors` key
 * (unlike PageController's message-only refusal): ServiceList::create()'s
 * catch only calls addError() when $e->errors is non-empty, and
 * ServiceList::delete()'s catch reads $e->errors[...] directly — a
 * message-only 422 here would show nothing to the operator at all. Mirrors
 * dawnsellshomes' ServiceController::store()/destroy() exactly. reorder()/
 * generate() don't exist for the same reason they don't exist there: this
 * screen never calls them without 'service-content'/a reorder control, so
 * a 404 (SiteApiNotSupported, handled calmly wherever it might matter) is
 * the correct answer rather than a stub.
 */
class ServiceController extends Controller
{
    use BuildsApiResponses;

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->rows()]);
    }

    public function show(int $service): JsonResponse
    {
        [$override, $areaKey, $area, $sortOrder] = $this->findOrAbort($service);

        return $this->itemResponse($this->toServiceArray($override, $areaKey, $area, $sortOrder));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'message' => "Services here are the site's own feature areas, wired straight into the marketing pages — a new one needs a page built and added to config/marketing.php, so it can't be added from this screen. Ask a developer.",
            'errors' => ['name' => [
                "Services here are the site's feature areas — a new one can't be added from this screen.",
            ]],
        ], 422);
    }

    /**
     * name -> the area's page title override, blurb -> its meta_description
     * override — the same App\Models\PageSeoOverride row and the same
     * App\Support\PageSeoOverrides::apply() write (cache-busting included)
     * the Pages screen's PageController@update uses, never a second copy.
     */
    public function update(Request $request, int $service): JsonResponse
    {
        [$override, $areaKey, $area, $sortOrder] = $this->findOrAbort($service);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'blurb' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $fields = [];
        if (array_key_exists('name', $data)) {
            $fields['title'] = $data['name'];
        }
        if (array_key_exists('blurb', $data)) {
            $fields['meta_description'] = $data['blurb'];
        }

        PageSeoOverrides::apply($override, $fields);

        return $this->itemResponse($this->toServiceArray($override->fresh(), $areaKey, $area, $sortOrder));
    }

    public function destroy(int $service): JsonResponse
    {
        $this->findOrAbort($service);

        return response()->json([
            'message' => "This is one of the site's own feature-area pages and can't be removed from this screen — it would 404 for anyone who still links to it. Ask a developer to retire the page.",
            'errors' => ['service' => [
                "This is one of the site's feature-area pages and can't be removed from this screen.",
            ]],
        ], 422);
    }

    /** @return array<int, array<string, mixed>> */
    protected function rows(): array
    {
        $out = [];
        $sortOrder = 0;

        foreach (config('marketing.areas', []) as $areaKey => $area) {
            $sortOrder++;
            $override = PageSeoOverride::findOrCreateFor('welcome.'.$areaKey, []);
            $out[] = $this->toServiceArray($override, $areaKey, $area, $sortOrder);
        }

        return $out;
    }

    /** @return array{0: PageSeoOverride, 1: string, 2: array<string, mixed>, 3: int} */
    protected function findOrAbort(int $id): array
    {
        $override = PageSeoOverride::find($id);
        abort_if(! $override, 404);

        $areaKey = $this->areaKeyForRoute($override->route_name);
        abort_if($areaKey === null, 404);

        $areas = config('marketing.areas', []);
        abort_if(! array_key_exists($areaKey, $areas), 404);

        $sortOrder = array_search($areaKey, array_keys($areas), true) + 1;

        return [$override, $areaKey, $areas[$areaKey], $sortOrder];
    }

    protected function areaKeyForRoute(string $routeName): ?string
    {
        if (! str_starts_with($routeName, 'welcome.') || str_starts_with($routeName, 'welcome.homeowners')) {
            return null;
        }

        $key = substr($routeName, strlen('welcome.'));

        return $key !== 'feature' && $key !== 'faq' ? $key : null;
    }

    /** @param  array<string, mixed>  $area */
    protected function toServiceArray(PageSeoOverride $override, string $areaKey, array $area, int $sortOrder): array
    {
        return [
            'id' => $override->id,
            'name' => $override->title ?: $area['label'],
            'slug' => $areaKey,
            'blurb' => $override->meta_description ?: ($area['eyebrow'].' — '.$area['grid_heading']),
            // No landing-page generator and no Projects domain on hive —
            // both always read the same, harmless value (see class docblock).
            'is_landing_page' => true,
            'sort_order' => $sortOrder,
            'projects_count' => 0,
            'intro' => null,
            'what_we_do' => null,
            'ideal_for' => null,
            'faq' => [],
            'sections' => [],
            'section_labels' => [],
            'public_url' => marketing_url(route('welcome.'.$areaKey, ['locale' => 'en'], false)),
            'generating' => false,
            'generation_error' => null,
        ];
    }
}
