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
        Schema::create('checks', function (Blueprint $table) {
            $table->id();
            // $table->decimal('total_amount');
            $table->string('check_type'); // Check, Transfer, Cash (billpay?)
            $table->integer('check_number')->nullable(); //number for check
            $table->date('date');
            $table->integer('bank_account_id')->unsigned()->nullable();
            $table->integer('user_id')->unsigned()->nullable();
            $table->integer('vendor_id')->unsigned()->nullable();
            $table->integer('belongs_to_vendor_id');
            $table->integer('created_by_user_id');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check');
    }
};
