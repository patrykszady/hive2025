<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public marketing blog's own rows — ordinary DB posts (never the
 * verbatim-HTML pages dawnsellshomes.com's `pages` table holds; there's no
 * Projects domain on this app, so there's no head_html/canonical machinery
 * to keep in sync here). Backs the public /{locale}/blog[/…] pages and the
 * central admin's Blog screen (App\Http\Controllers\Api\Admin\V1\
 * BlogPostController, routes/api-admin/blog.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->longText('body_html');
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('status')->default('draft'); // draft|published
            $table->timestamp('published_at')->nullable();
            $table->string('cover_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
