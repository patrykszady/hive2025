<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a sub's wording means in our catalog, learned from the compare
     * modal: "2'' cans" → our 4" Recessed. Per sub when the sub is known
     * (sub_vendor_id), otherwise for any sub; remembered the moment an admin
     * matches a line by hand, confirmed each time a match is applied.
     */
    public function up(): void
    {
        Schema::create('sub_item_aliases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('belongs_to_vendor_id');
            $table->unsignedBigInteger('sub_vendor_id')->nullable();
            $table->string('description_key', 191);
            $table->string('description', 500);
            $table->unsignedBigInteger('line_item_id')->index();
            $table->string('source', 16);
            $table->unsignedInteger('times_used')->default(1);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['belongs_to_vendor_id', 'sub_vendor_id', 'description_key'], 'sub_item_aliases_scope_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_item_aliases');
    }
};
