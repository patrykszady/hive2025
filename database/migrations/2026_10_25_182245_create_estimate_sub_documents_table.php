<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A subcontractor's updated estimate or invoice uploaded against one of
     * our estimates (App\Livewire\Estimates\EstimateSubDocumentCompare): the
     * file, what the AI read off it, the change-order additions and credits
     * it proposed against the estimate, and what was applied.
     */
    public function up(): void
    {
        Schema::create('estimate_sub_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('estimate_id')->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('belongs_to_vendor_id')->index();
            $table->unsignedBigInteger('sub_vendor_id')->nullable()->index();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->string('status', 24)->index();
            $table->string('file_disk', 32);
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->json('document')->nullable();
            $table->json('proposals')->nullable();
            $table->json('applied')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('model')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_sub_documents');
    }
};
