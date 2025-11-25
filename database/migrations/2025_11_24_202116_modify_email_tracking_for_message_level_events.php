<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('email_tracking', function (Blueprint $table) {
            // Change recipient_email to allow JSON for message-level events
            // For individual recipient tracking: recipient_email = "user@example.com"
            // For message-level tracking: recipient_email = ["user1@example.com", "user2@example.com"]
            $table->json('recipient_emails')->nullable()->after('recipient_email');
        });

        // Migrate existing data: move recipient_email to recipient_emails as single-item array
        DB::statement('UPDATE email_tracking SET recipient_emails = JSON_ARRAY(recipient_email) WHERE recipient_email IS NOT NULL');
        
        Schema::table('email_tracking', function (Blueprint $table) {
            // Drop old column
            $table->dropColumn('recipient_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_tracking', function (Blueprint $table) {
            // Restore original structure
            $table->string('recipient_email')->nullable()->after('event_type');
        });

        // Migrate data back: take first email from JSON array
        DB::statement('UPDATE email_tracking SET recipient_email = JSON_UNQUOTE(JSON_EXTRACT(recipient_emails, "$[0]")) WHERE recipient_emails IS NOT NULL');

        Schema::table('email_tracking', function (Blueprint $table) {
            $table->dropColumn('recipient_emails');
        });
    }
};
