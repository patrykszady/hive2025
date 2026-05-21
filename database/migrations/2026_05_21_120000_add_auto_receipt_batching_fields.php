<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_receipt_email_batches', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->unique();
            $table->unsignedBigInteger('company_email_id')->nullable()->index();
            $table->unsignedBigInteger('belongs_to_vendor_id')->nullable()->index();
            $table->string('from_email')->nullable();
            $table->string('subject')->nullable();
            $table->timestamp('email_received_at')->nullable()->index();
            $table->unsignedInteger('attachment_count')->default(0);
            $table->unsignedInteger('processed_receipt_count')->default(0);
            $table->timestamps();
        });

        Schema::table('expense_receipts_data', function (Blueprint $table) {
            $table->string('auto_receipt_message_id')->nullable()->after('is_material_order')->index();
            $table->unsignedInteger('auto_receipt_attachment_index')->nullable()->after('auto_receipt_message_id')->index();
            $table->timestamp('auto_receipt_email_received_at')->nullable()->after('auto_receipt_attachment_index')->index();
        });
    }

    public function down(): void
    {
        Schema::table('expense_receipts_data', function (Blueprint $table) {
            $table->dropIndex(['auto_receipt_message_id']);
            $table->dropIndex(['auto_receipt_attachment_index']);
            $table->dropIndex(['auto_receipt_email_received_at']);
            $table->dropColumn([
                'auto_receipt_message_id',
                'auto_receipt_attachment_index',
                'auto_receipt_email_received_at',
            ]);
        });

        Schema::dropIfExists('auto_receipt_email_batches');
    }
};
