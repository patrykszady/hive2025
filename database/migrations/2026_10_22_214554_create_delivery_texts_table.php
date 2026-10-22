<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per inbound text the delivery reader judged
     * (App\Services\Deliveries\DeliveryTextIntake), so each text is read
     * once: what it said, which project it was placed on, and the task it
     * scheduled or moved.
     */
    public function up(): void
    {
        Schema::create('delivery_texts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sms_message_id')->unique();
            $table->unsignedBigInteger('belongs_to_vendor_id')->index();
            $table->string('outcome', 32);
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('task_id')->nullable()->index();
            $table->string('supplier')->nullable();
            $table->string('order_number')->nullable()->index();
            $table->date('delivery_date')->nullable();
            $table->json('extracted')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_texts');
    }
};
