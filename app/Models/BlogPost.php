<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * The public marketing blog's posts — plain DB rows (title/body_html/etc),
 * never the AI-drafted, project-linked posts gsc/jpeterson-design carry
 * (this app has no Projects domain), and never dawnsellshomes.com's
 * verbatim-HTML `pages` rows either. `writer` is always 'manual' and
 * `project_id`/`project_title`/`dated_at` are always null in
 * toAdminApiArray() — the central admin's shared Blog screen
 * (App\Livewire\Admin\BlogPostList/BlogPostForm in ss-systems) reads those
 * the same way it does for dawnsellshomes.
 */
class BlogPost extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'excerpt',
        'body_html',
        'meta_title',
        'meta_description',
        'status',
        'published_at',
        'cover_url',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /** Published only — what the public /{locale}/blog pages and the sitemap show. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /**
     * A short teaser: the real excerpt when one was written, otherwise the
     * body's own opening text — a post created through the admin's "New
     * post" modal (BlogPostList::store()) never sends an excerpt, so the
     * blog index still needs something to show for it.
     */
    public function teaser(int $length = 160): string
    {
        $excerpt = trim((string) $this->excerpt);

        if ($excerpt !== '') {
            return $excerpt;
        }

        return str(strip_tags($this->body_html))->squish()->limit($length)->toString();
    }

    /**
     * Rooted on the marketing host (marketing_url(), never url()/APP_URL —
     * see MarketingSitemap's docblock), and always the default locale: the
     * same English post serves every locale, so this is the one canonical
     * address the admin's Blog screen links out to.
     */
    public function publicUrl(): string
    {
        return marketing_url(route('blog.show', [
            'locale' => config('locales.default', 'en'),
            'slug' => $this->slug,
        ], false));
    }

    /**
     * Signed + expiring (BlogPostForm's docblock: "a bare ?preview=1 is a
     * 404 there") so the admin can open a draft with no session on this
     * app at all. Null once published — the post is already public at
     * publicUrl(), and BlogPostForm/BlogPostList only show the Preview
     * button when this is present. Signed relative
     * (ValidateSignature::relative() on the route) and rooted on the
     * marketing host ourselves for the same reason publicUrl() is: the
     * signature must verify against the path the browser actually
     * requests, not against APP_URL, which can be a different host
     * (hub.hive.contractors) from the marketing site that serves this
     * route.
     */
    public function previewUrl(): ?string
    {
        if ($this->isPublished()) {
            return null;
        }

        $path = URL::temporarySignedRoute('blog.preview', now()->addDays(7), [
            'locale' => config('locales.default', 'en'),
            'slug' => $this->slug,
        ], false);

        return marketing_url($path);
    }

    /** @return array<string, mixed> every field BlogExtApiClient/BlogPostList/BlogPostForm read. */
    public function toAdminApiArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => null,
            'project_title' => null,
            'slug' => $this->slug,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'body' => null,
            'body_html' => $this->body_html,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'status' => $this->status,
            'writer' => 'manual',
            'published_at' => optional($this->published_at)->toIso8601String(),
            'dated_at' => null,
            'url' => $this->publicUrl(),
            'preview_url' => $this->previewUrl(),
            'cover_url' => $this->cover_url,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
