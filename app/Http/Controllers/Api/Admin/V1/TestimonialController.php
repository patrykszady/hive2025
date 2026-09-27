<?php

namespace App\Http\Controllers\Api\Admin\V1;

use SsSystems\Platform\Http\Admin\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Testimonial;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The central admin's Reviews screen (ss-systems' App\Livewire\Admin\
 * {TestimonialList,TestimonialForm}), backed by the `testimonials` table.
 * The wire shape (request/response field names) matches gsc's
 * TestimonialController exactly — reviewer_name, project_location,
 * project_type, review_description, review_date, review_url, star_rating,
 * is_hidden — so the same admin screens/forms work unmodified; only the
 * storage column names differ (see App\Models\Testimonial). Deliberately
 * NOT implemented as its own table: gsc's review_urls pivot (multi-platform
 * links) and testimonial<->project linking — this app declares neither
 * 'review-platforms' nor 'testimonial-projects' in PingController, so the
 * admin never renders those parts of the form/list for this site. There is
 * no dedicated publish-toggle route: the admin flips `is_hidden` through
 * the same update() a normal edit uses.
 *
 * The review_urls SHAPE is still accepted on store()/update() (see
 * rules()/mapToColumns()): ss.systems' Google Business Profile review
 * import always sends that gsc/jpeterson-design pivot shape, one entry per
 * call, regardless of which sites declare the real pivot — its one entry
 * maps onto this row's own platform/review_url/external_id columns, same
 * as dawnsellshomes.com's identical port.
 */
class TestimonialController extends Controller
{
    use BuildsApiResponses;

    /** Request field => testimonials column, for both store() and update(). */
    protected const FIELD_MAP = [
        'reviewer_name' => 'name',
        'project_location' => 'role',
        'review_description' => 'body',
        'review_date' => 'review_date',
        'review_url' => 'review_url',
        'external_id' => 'external_id',
        'star_rating' => 'rating',
        'platform' => 'platform',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = Testimonial::query();

        if ($search = $request->string('search')->toString()) {
            $query->where('name', 'like', "%{$search}%");
        }

        // Mirrors gsc's wire shape: the admin sends `hidden=1`/`hidden=0`,
        // never a bare "status" or "published" param.
        if ($request->has('hidden')) {
            $query->where('is_published', ! $request->boolean('hidden'));
        }

        if ($request->filled('star_rating')) {
            $query->where('rating', (int) $request->string('star_rating')->toString());
        }

        if ($platform = $request->string('platform')->toString()) {
            $query->where('platform', $platform);
        }

        $this->applySort($query, $request->string('sort')->toString() ?: null, '-review_date');

        $paginator = $query->paginate($this->perPage($request));

        return $this->paginatedResponse($paginator, fn (Testimonial $t) => $t->toApiArray());
    }

    /**
     * Distinct platforms for the list's filter dropdown. No project types
     * here (this app has none), so that half of the shared contract is
     * always empty — the admin's Type filter simply doesn't render.
     */
    public function filters(): JsonResponse
    {
        $platforms = Testimonial::query()
            ->whereNotNull('platform')
            ->where('platform', '!=', '')
            ->distinct()
            ->orderBy('platform')
            ->pluck('platform')
            ->map(fn (string $platform) => [
                'value' => $platform,
                'label' => ucfirst($platform),
                'icon' => null,
            ])
            ->values()
            ->all();

        return $this->itemResponse([
            'project_types' => [],
            'platforms' => $platforms,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $testimonial = Testimonial::create($this->mapToColumns($data));

        // fresh(): is_published isn't in mapToColumns() unless is_hidden was
        // sent, so a plain create() leaves the in-memory model without it —
        // reload to pick up the schema default (published) rather than
        // reporting every new review as hidden.
        return $this->itemResponse($testimonial->fresh()->toApiArray(), 201);
    }

    public function show(int $testimonial): JsonResponse
    {
        $model = Testimonial::findOrFail($testimonial);

        return $this->itemResponse($model->toApiArray());
    }

    public function update(Request $request, int $testimonial): JsonResponse
    {
        $model = Testimonial::findOrFail($testimonial);

        $data = $request->validate($this->rules());

        $model->update($this->mapToColumns($data));

        return $this->itemResponse($model->fresh()->toApiArray());
    }

    public function destroy(int $testimonial): Response
    {
        Testimonial::findOrFail($testimonial)->delete();

        return response()->noContent();
    }

    /**
     * Translate a validated request payload (gsc's field names) into
     * testimonials columns — only the keys actually present, so a partial
     * PUT never clobbers a column the caller didn't send (Eloquent's
     * update()/create() only touch what's in the array; is_published gets
     * the same treatment via is_hidden's presence, not its value).
     */
    protected function mapToColumns(array $data): array
    {
        $out = [];

        foreach (self::FIELD_MAP as $field => $column) {
            if (array_key_exists($field, $data)) {
                $out[$column] = $data[$field];
            }
        }

        if (array_key_exists('is_hidden', $data)) {
            $out['is_published'] = ! $data['is_hidden'];
        }

        // ss.systems' Google Business Profile review import (App\Livewire\
        // Admin\PlatformsSettings::importGbpReviews() there) sends the
        // gsc/jpeterson-design review_urls pivot shape —
        // review_urls: [{platform, url, external_id}] — even though this
        // app has no review_urls table. There is exactly one entry per
        // import call; its three fields map onto this row's own platform/
        // review_url/external_id columns, taking precedence over (or
        // filling in behind) any of those three sent directly. Ported from
        // dawnsellshomes.com's identical translation.
        if (is_array($data['review_urls'] ?? null) && ($first = $data['review_urls'][0] ?? null) && is_array($first)) {
            if (array_key_exists('platform', $first)) {
                $out['platform'] = $first['platform'];
            }
            if (array_key_exists('url', $first)) {
                $out['review_url'] = $first['url'];
            }
            if (array_key_exists('external_id', $first)) {
                $out['external_id'] = $first['external_id'];
            }
        }

        return $out;
    }

    /** '-field' / 'field' sort (leading '-' = descending), whitelisted to real columns. */
    protected function applySort(Builder $query, ?string $sort, string $default): void
    {
        $spec = $sort ?: $default;
        $direction = str_starts_with($spec, '-') ? 'desc' : 'asc';
        $column = ltrim($spec, '-');

        if (! in_array($column, ['name', 'rating', 'review_date', 'platform', 'created_at', 'updated_at', 'id'], true)) {
            $column = 'review_date';
        }

        $query->orderBy($column, $direction);
    }

    /**
     * project_type is accepted (never rejected) but has no column here —
     * TestimonialForm always includes it in its payload regardless of
     * capability, so it must validate cleanly; it's simply dropped before
     * the model write (see mapToColumns()).
     */
    protected function rules(): array
    {
        return [
            'reviewer_name' => ['required', 'string', 'max:255'],
            'project_location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'project_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'review_description' => ['required', 'string'],
            'review_date' => ['sometimes', 'nullable', 'date'],
            'review_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'external_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'star_rating' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'is_hidden' => ['sometimes', 'boolean'],
            'platform' => ['sometimes', 'nullable', 'string', 'max:50'],
            // The gsc/jpeterson-design review_urls pivot shape, accepted
            // (never rejected) so the GBP review importer's payload
            // validates cleanly — see mapToColumns() for where its one
            // entry lands.
            'review_urls' => ['sometimes', 'array'],
            'review_urls.*.platform' => ['sometimes', 'nullable', 'string', 'max:50'],
            'review_urls.*.url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'review_urls.*.external_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
