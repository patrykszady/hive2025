<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Receipt emails already copied into Hive Receipts from a mailbox. The
     * original used to be moved out of the inbox once forwarded, which is how
     * the forwarder knew not to send it again; it now stays where it is and
     * this table remembers it instead.
     */
    public function up(): void
    {
        Schema::create('forwarded_receipt_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_email_id')->index();
            $table->string('message_id');
            $table->string('forwarded_message_id')->nullable();
            $table->timestamps();

            $table->unique(['company_email_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forwarded_receipt_messages');
    }
};
