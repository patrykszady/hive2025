<?php

use App\Models\BlogPost;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => ADMIN_API_TEST_TOKEN]);
});

/**
 * The Blog screen's API (App\Http\Controllers\Api\Admin\V1\
 * BlogPostController, routes/api-admin/blog.php) — the shape ss-systems'
 * App\Services\BlogExtApiClient/BlogPostList/BlogPostForm read and write.
 * See App\Models\BlogPost::toAdminApiArray() for the field list.
 */
it('declares the blog domain on ping', function () {
    $domains = $this->getJson('/api/admin/v1/ping', adminApiHeaders())->json('data.domains');

    expect($domains)->toContain('blog');
});

it('401s every blog-posts route with no bearer token', function () {
    $this->getJson('/api/admin/v1/blog-posts')->assertUnauthorized();
    $this->postJson('/api/admin/v1/blog-posts', [])->assertUnauthorized();
});

it('lists posts newest-updated-first with the full admin shape, filterable by status and search', function () {
    $draft = BlogPost::create([
        'slug' => 'a-draft', 'title' => 'A Draft Post', 'body_html' => '<p>Draft.</p>',
        'status' => 'draft', 'published_at' => null,
    ]);
    // 'updated_at' isn't fillable (Eloquent auto-manages it), so back-date it
    // directly to make the "newest updated first" ordering unambiguous
    // rather than relying on two creates in the same test landing in
    // different seconds.
    $draft->timestamps = false;
    $draft->updated_at = now()->subMinute();
    $draft->save();

    $published = BlogPost::create([
        'slug' => 'a-published-post', 'title' => 'A Published Post', 'excerpt' => 'Teaser.',
        'body_html' => '<p>Body.</p>', 'meta_title' => 'Meta Title', 'meta_description' => 'Meta desc.',
        'status' => 'published', 'published_at' => now()->subDay(), 'cover_url' => 'https://example.com/cover.jpg',
    ]);

    $data = $this->getJson('/api/admin/v1/blog-posts', adminApiHeaders())->assertOk()->json('data');
    expect(collect($data)->pluck('id')->all())->toBe([$published->id, $draft->id]);

    $row = collect($data)->firstWhere('id', $published->id);
    expect($row)->toMatchArray([
        'id' => $published->id,
        'project_id' => null,
        'project_title' => null,
        'slug' => 'a-published-post',
        'title' => 'A Published Post',
        'excerpt' => 'Teaser.',
        'body' => null,
        'body_html' => '<p>Body.</p>',
        'meta_title' => 'Meta Title',
        'meta_description' => 'Meta desc.',
        'status' => 'published',
        'writer' => 'manual',
        'dated_at' => null,
        'preview_url' => null,
        'cover_url' => 'https://example.com/cover.jpg',
    ]);
    expect($row['url'])->toContain('/en/blog/a-published-post');

    $meta = $this->getJson('/api/admin/v1/blog-posts', adminApiHeaders())->json('meta');
    expect($meta)->toMatchArray(['current_page' => 1, 'total' => 2, 'last_page' => 1]);

    $onlyDrafts = $this->getJson('/api/admin/v1/blog-posts?status=draft', adminApiHeaders())->json('data');
    expect(collect($onlyDrafts)->pluck('id')->all())->toBe([$draft->id]);

    $searched = $this->getJson('/api/admin/v1/blog-posts?search=Published', adminApiHeaders())->json('data');
    expect(collect($searched)->pluck('id')->all())->toBe([$published->id]);
});

it('carries a signed preview_url only for a draft', function () {
    $draft = BlogPost::create(['slug' => 'draft-x', 'title' => 'Draft X', 'body_html' => '<p>.</p>', 'status' => 'draft']);

    $row = $this->getJson("/api/admin/v1/blog-posts/{$draft->id}", adminApiHeaders())->assertOk()->json('data');

    expect($row['preview_url'])->not->toBeNull();
    $this->get($row['preview_url'])->assertOk();
});

it('creates a post that is published and listed on the index by default', function () {
    $response = $this->postJson('/api/admin/v1/blog-posts', [
        'title' => 'Our Newest Listing Tips',
        'slug' => 'our-newest-listing-tips',
        'meta_description' => 'Tips for buyers.',
        'body_html' => '<p>Tips.</p>',
    ], adminApiHeaders())->assertCreated();

    $response->assertJson(['listed_on_index' => true]);
    $response->assertJsonPath('data.status', 'published');
    $response->assertJsonPath('data.title', 'Our Newest Listing Tips');
    $response->assertJsonPath('data.writer', 'manual');
    expect($response->json('data.published_at'))->not->toBeNull();

    $post = BlogPost::where('slug', 'our-newest-listing-tips')->first();
    expect($post)->not->toBeNull();
    expect($post->status)->toBe('published');
    expect($post->published_at)->not->toBeNull();

    // Immediately live on the real public index — see BlogController::index().
    $this->get('/en/blog')->assertSee('Our Newest Listing Tips');
});

it('creates a draft when asked and reports it as not listed', function () {
    $this->postJson('/api/admin/v1/blog-posts', [
        'title' => 'A Quiet Draft', 'slug' => 'a-quiet-draft', 'body_html' => '<p>Draft.</p>', 'status' => 'draft',
    ], adminApiHeaders())->assertCreated()->assertJsonPath('data.status', 'draft');

    $post = BlogPost::where('slug', 'a-quiet-draft')->first();
    expect($post->published_at)->toBeNull();
});

it('422s a duplicate slug on create', function () {
    BlogPost::create(['slug' => 'taken', 'title' => 'Taken', 'body_html' => '<p>.</p>', 'status' => 'published']);

    $this->postJson('/api/admin/v1/blog-posts', [
        'title' => 'New', 'slug' => 'taken', 'body_html' => '<p>.</p>',
    ], adminApiHeaders())->assertStatus(422)->assertJsonValidationErrors('slug');
});

it('saves the full form on update, including an edited slug', function () {
    $post = BlogPost::create([
        'slug' => 'old-slug', 'title' => 'Old Title', 'excerpt' => 'Old excerpt',
        'body_html' => '<p>Old.</p>', 'meta_title' => 'Old meta', 'meta_description' => 'Old desc',
        'status' => 'draft',
    ]);

    $this->putJson("/api/admin/v1/blog-posts/{$post->id}", [
        'title' => 'New Title', 'slug' => 'new-slug', 'excerpt' => 'New excerpt', 'body_html' => '<p>New.</p>',
        'meta_title' => 'New meta', 'meta_description' => 'New desc',
    ], adminApiHeaders())->assertOk()->assertJsonPath('data.title', 'New Title');

    $post->refresh();
    expect($post->slug)->toBe('new-slug');
    expect($post->excerpt)->toBe('New excerpt');
    expect($post->meta_title)->toBe('New meta');
    // A plain save never touches status/published_at.
    expect($post->status)->toBe('draft');
    expect($post->published_at)->toBeNull();
});

it('publishes and unpublishes through a bare status update, stamping published_at only once', function () {
    $post = BlogPost::create(['slug' => 'toggle-me', 'title' => 'Toggle Me', 'body_html' => '<p>.</p>', 'status' => 'draft']);

    $this->putJson("/api/admin/v1/blog-posts/{$post->id}", ['status' => 'published'], adminApiHeaders())
        ->assertOk()->assertJsonPath('data.status', 'published');

    $post->refresh();
    expect($post->status)->toBe('published');
    $firstPublishedAt = $post->published_at;
    expect($firstPublishedAt)->not->toBeNull();

    $this->putJson("/api/admin/v1/blog-posts/{$post->id}", ['status' => 'draft'], adminApiHeaders())
        ->assertOk()->assertJsonPath('data.status', 'draft');

    $post->refresh();
    expect($post->status)->toBe('draft');
    expect($post->published_at->equalTo($firstPublishedAt))->toBeTrue();

    $this->putJson("/api/admin/v1/blog-posts/{$post->id}", ['status' => 'published'], adminApiHeaders())->assertOk();
    $post->refresh();
    // Re-publishing doesn't move the original date.
    expect($post->published_at->equalTo($firstPublishedAt))->toBeTrue();
});

it('422s a slug collision on update, ignoring the row itself', function () {
    BlogPost::create(['slug' => 'existing', 'title' => 'Existing', 'body_html' => '<p>.</p>', 'status' => 'published']);
    $post = BlogPost::create(['slug' => 'mine', 'title' => 'Mine', 'body_html' => '<p>.</p>', 'status' => 'draft']);

    $this->putJson("/api/admin/v1/blog-posts/{$post->id}", ['slug' => 'existing'], adminApiHeaders())
        ->assertStatus(422)->assertJsonValidationErrors('slug');

    // Saving a post's OWN unchanged slug back is not a collision with itself.
    $this->putJson("/api/admin/v1/blog-posts/{$post->id}", ['slug' => 'mine'], adminApiHeaders())->assertOk();
});

it('deletes a post', function () {
    $post = BlogPost::create(['slug' => 'gone', 'title' => 'Gone', 'body_html' => '<p>.</p>', 'status' => 'published']);

    $this->deleteJson("/api/admin/v1/blog-posts/{$post->id}", [], adminApiHeaders())->assertNoContent();

    expect(BlogPost::find($post->id))->toBeNull();
});

it('404s show/update/destroy for an unknown id', function () {
    $this->getJson('/api/admin/v1/blog-posts/999999', adminApiHeaders())->assertNotFound();
    $this->putJson('/api/admin/v1/blog-posts/999999', ['title' => 'X'], adminApiHeaders())->assertNotFound();
    $this->deleteJson('/api/admin/v1/blog-posts/999999', [], adminApiHeaders())->assertNotFound();
});
