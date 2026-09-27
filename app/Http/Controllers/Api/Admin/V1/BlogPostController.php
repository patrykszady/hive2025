<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ss-systems' Blog screen (App\Livewire\Admin\BlogPostList/BlogPostForm,
 * App\Services\BlogExtApiClient there) — this site's own manual posts.
 * Ported from dawnsellshomes.com's no-projects shape (see that repo's
 * Api\Admin\V1\BlogPostController and Page::toBlogApiArray()), but backed
 * by a real App\Models\BlogPost row rather than a verbatim-HTML `pages`
 * row: excerpt/meta_title/meta_description/cover_url/published_at are
 * real columns here, not aliases onto title/meta_description, and — unlike
 * Dawn's — slug IS editable (BlogPostForm's Slug field sends it on every
 * save): there is no page-path/sitemap structure tying it down the way
 * Dawn's `pages.slug` is.
 *
 * writer is always 'manual' and project_id/project_title/dated_at are
 * always null (BlogPost::toAdminApiArray()) — this app has no Projects
 * domain, so there is never an AI-drafted post to regenerate, and
 * PingController never declares 'projects' — see BlogPostList::
 * hasProjects()/BlogPostForm::hasProjects() on ss-systems, which gate the
 * project wording and the Regenerate control off of exactly that.
 */
class BlogPostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BlogPost::query()->orderByDesc('updated_at');

        if ($search = trim((string) $request->query('search'))) {
            $query->where('title', 'like', '%'.$search.'%');
        }

        match ($request->query('status')) {
            'published' => $query->where('status', 'published'),
            'draft' => $query->where('status', 'draft'),
            default => null, // null/''/omitted from BlogPostList's "All" filter — every status
        };

        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (BlogPost $post) => $post->toAdminApiArray())->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(BlogPost $post): JsonResponse
    {
        return response()->json(['data' => $post->toAdminApiArray()]);
    }

    /**
     * New post (BlogPostList::store() — the manual "New post" modal only
     * offered because this site declares no 'projects' domain). Always
     * `listed_on_index: true`: unlike Dawn's hand-written /blog page, the
     * public index (App\Http\Controllers\BlogController::index()) queries
     * this table directly, so a new post is on it immediately. Defaults to
     * published (the modal sends no `status` at all) with published_at
     * stamped now — the same default Dawn's writer uses.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', 'unique:blog_posts,slug'],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:500'],
            'body_html' => ['required', 'string'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:191'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'cover_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
        ]);

        $status = $data['status'] ?? 'published';

        $post = BlogPost::create([
            'title' => $data['title'],
            'slug' => $data['slug'],
            'excerpt' => $data['excerpt'] ?? null,
            'body_html' => $data['body_html'],
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'cover_url' => $data['cover_url'] ?? null,
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);

        return response()->json([
            'data' => $post->toAdminApiArray(),
            'listed_on_index' => true,
        ], 201);
    }

    /**
     * The full-form save (BlogPostForm::save(), which always sends title/
     * slug/excerpt/body_html/meta_title/meta_description together) and a
     * bare status toggle (BlogPostList::setStatus(), BlogPostForm::
     * publish()/unpublish()) both land here — there is no separate
     * publish/unpublish endpoint; see BlogPostForm's docblock, both of
     * those just call update($id, ['status' => …]). published_at is
     * stamped the first time a post turns published and left alone after
     * — an unpublish-then-republish keeps its original date, same as
     * Dawn's in_sitemap-driven timestamp never moving once set.
     */
    public function update(Request $request, BlogPost $post): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('blog_posts', 'slug')->ignore($post->id)],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:500'],
            'body_html' => ['sometimes', 'string'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:191'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'cover_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
        ]);

        if (($data['status'] ?? null) === 'published' && ! $post->published_at) {
            $data['published_at'] = now();
        }

        $post->fill($data)->save();

        return response()->json(['data' => $post->fresh()->toAdminApiArray()]);
    }

    public function destroy(BlogPost $post): JsonResponse
    {
        $post->delete();

        return response()->json(null, 204);
    }
}
