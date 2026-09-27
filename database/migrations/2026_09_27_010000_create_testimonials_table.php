<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs the central admin's Reviews screen (ss-systems'
     * App\Livewire\Admin\{TestimonialList,TestimonialForm}) — same wire
     * contract as gsc/jpeterson's `testimonials` table (see
     * App\Models\Testimonial::toApiArray()), storage column names our own.
     * This app has no Projects domain, so — like dawnsellshomes.com's
     * version of this table — there is no review_urls pivot (multi-platform
     * links) or testimonial<->project pivot: a single review_url +
     * external_id pair per row covers "where this review came from", and
     * this app declares neither 'review-platforms' nor
     * 'testimonial-projects' in PingController, so the admin's richer
     * editing surface for those simply doesn't render here.
     */
    public function up(): void
    {
        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Free text shown next to the name — a company/trade line
            // ("Owner, ABC Roofing") for a contractor customer's review of
            // Hive itself. Serialized as the shared contract's
            // `project_location`.
            $table->string('role')->nullable();
            $table->text('body');
            $table->unsignedTinyInteger('rating')->nullable();
            // Where the review came from: 'site' for one entered directly,
            // 'google'/'facebook'/etc. for anything imported later.
            $table->string('platform')->nullable();
            $table->string('review_url', 2048)->nullable();
            // The platform's own id for this review, so a future import can
            // tell what's already here.
            $table->string('external_id')->nullable();
            $table->date('review_date')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('testimonials');
    }
};
