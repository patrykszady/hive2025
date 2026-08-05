<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Add to Project" on a text message pins that MESSAGE to a project, so
     * its photos appear under the project's Message Images — with the real
     * sender — even when the thread itself isn't linked to the project.
     * Nothing is copied; the photos keep living in the message.
     */
    public function up(): void
    {
        Schema::create('project_pinned_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('sms_message_id')->index();
            $table->unsignedBigInteger('added_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'sms_message_id']);
        });
    }

    public function down(): void
    {
        Schema::drop('project_pinned_messages');
    }
};
