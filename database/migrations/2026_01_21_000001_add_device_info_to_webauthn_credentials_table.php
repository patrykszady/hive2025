<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webauthn_credentials', function (Blueprint $table): void {
            $table->string('device_type')->nullable()->after('alias');
            $table->string('device_name')->nullable()->after('device_type');
            $table->string('user_agent', 512)->nullable()->after('device_name');
        });
    }

    public function down(): void
    {
        Schema::table('webauthn_credentials', function (Blueprint $table): void {
            $table->dropColumn(['device_type', 'device_name', 'user_agent']);
        });
    }
};
