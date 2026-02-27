<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('estimate_signatures', function (Blueprint $table) {
            $table->string('signer_phone', 20)->nullable()->after('signer_email');
            $table->foreignId('user_id')->nullable()->after('estimate_id')->constrained()->nullOnDelete();
        });

        // Drop the old unique-ish single-signature assumption — allow multiple per estimate.
        // The estimate_id foreign key index already exists from the create migration.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_signatures', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['signer_phone', 'user_id']);
        });
    }
};
