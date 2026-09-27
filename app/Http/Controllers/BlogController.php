<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public marketing blog — /{locale}/blog and /{locale}/blog/{slug}
 * (routes/web.php, inside the {locale}/SetLocale group, same as every other
 * marketing page). Deliberately NOT behind CachePublicPage: pagination and
 * a draft's 404 both need a fresh render every request, unlike the static
 * /{locale}/welcome set.
 *
 * Every method takes $locale first (unused) even though it's never read:
 * Laravel binds a controller method's plain scalar parameters by ORDER, not
 * by route-segment NAME (see routes/web.php's welcome.feature closure —
 * "closure args bind positionally — omitting $locale would shift the
 * locale value into $area"; the exact same rule applies to a controller
 * method). Dropping $locale here would silently bind $slug to the locale
 * code instead of the actual slug.
 */
class BlogController extends Controller
{
    public function index(Request $request, string $locale): View
    {
        $posts = BlogPost::published()
            ->orderByDesc('published_at')
            ->paginate(10)
            ->withQueryString();

        return view('blog.index', ['posts' => $posts]);
    }

    public function show(string $locale, string $slug): View
    {
        // Drafts 404 publicly — see BlogController::preview() for the
        // signed link that lets the admin open one anyway.
        $post = BlogPost::published()->where('slug', $slug)->firstOrFail();

        return view('blog.show', ['post' => $post]);
    }

    /**
     * Opened only from the signed link the admin's Blog screen shows
     * (BlogPost::previewUrl(), never offered once a post is published —
     * the 'signed:relative' route middleware is the only gate here, no
     * auth/session on this app at all).
     */
    public function preview(string $locale, string $slug): View
    {
        $post = BlogPost::where('slug', $slug)->firstOrFail();

        return view('blog.show', ['post' => $post, 'isPreview' => true]);
    }
}
