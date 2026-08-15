<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * latestMessage is a latestOfMany('created_at'): its derived table computes
 * MAX(created_at)/MAX(id) GROUP BY thread_id, which without this index scanned
 * all ~41k sms_messages twice per query (the sidebar unread badge does this on
 * every page load — 69ms of its 95ms). The composite lets MySQL resolve the
 * group-by from the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->index(['thread_id', 'created_at'], 'sms_messages_thread_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropIndex('sms_messages_thread_created_index');
        });
    }
};
