<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a village's permit portal said about a permit number — the house
     * it was issued for — kept so the expense matcher asks each portal once.
     */
    public function up(): void
    {
        Schema::create('permit_lookups', function (Blueprint $table) {
            $table->id();
            $table->string('portal', 40);
            $table->string('permit_number', 40);
            $table->boolean('found')->default(false);
            $table->string('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('state', 8)->nullable();
            $table->string('zip', 12)->nullable();
            $table->string('status', 60)->nullable();
            $table->string('case_type')->nullable();
            $table->text('description')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('looked_up_at')->nullable();
            $table->timestamps();

            $table->unique(['portal', 'permit_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permit_lookups');
    }
};
