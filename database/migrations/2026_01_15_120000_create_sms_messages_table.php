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
		Schema::create('sms_messages', function (Blueprint $table) {
			$table->id();
			$table->string('provider')->nullable()->index();
			$table->string('provider_message_id')->nullable()->index();
			$table->string('direction');
			$table->string('from_number')->nullable();
			$table->json('to_numbers')->nullable();
			$table->text('text')->nullable();
			$table->json('raw_payload')->nullable();
			$table->timestamps();
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('sms_messages');
	}
};
