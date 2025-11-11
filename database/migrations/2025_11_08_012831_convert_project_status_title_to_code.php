<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_status', function (Blueprint $table) {
            // Add new status_code column before we drop title
            $table->unsignedTinyInteger('status_code')->nullable()->after('belongs_to_vendor_id');
        });

        // Backfill status_code from existing string titles
        $map = [
            'Invited' => 1,
            'Estimate' => 2,
            'Awaiting Response' => 3,
            'Project Prep' => 4,
            'Scheduled' => 5,
            'Active' => 6,
            'Complete' => 7,
            'Service Call' => 8,
            'Service Call Complete' => 9,
            'Cancelled' => 10,
            'VIEW ONLY' => 11,
        ];

        DB::table('project_status')
            ->select('id','title')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($map) {
                foreach ($rows as $row) {
                    $code = $map[$row->title] ?? null;
                    DB::table('project_status')->where('id', $row->id)->update(['status_code' => $code]);
                }
            });

        // Ensure no nulls remain (set to Invited = 1 as default)
        DB::table('project_status')->whereNull('status_code')->update(['status_code' => 1]);

        // Consolidate Service Call Complete (9) -> Complete (7)
        DB::table('project_status')->where('status_code', 9)->update(['status_code' => 7]);

        // Make column not nullable now that it is populated
        Schema::table('project_status', function (Blueprint $table) {
            $table->unsignedTinyInteger('status_code')->default(1)->nullable(false)->change();
        });

        // Drop old title column
        Schema::table('project_status', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate title column
        Schema::table('project_status', function (Blueprint $table) {
            $table->string('title')->nullable()->after('belongs_to_vendor_id');
        });

        // Reverse backfill
        $reverse = [
            1 => 'Invited',
            2 => 'Estimate',
            3 => 'Awaiting Response',
            4 => 'Project Prep',
            5 => 'Scheduled',
            6 => 'Active',
            7 => 'Complete',
            8 => 'Service Call',
            9 => 'Service Call Complete',
            10 => 'Cancelled',
            11 => 'VIEW ONLY',
        ];

        DB::table('project_status')
            ->select('id','status_code')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($reverse) {
                foreach ($rows as $row) {
                    $title = $reverse[$row->status_code] ?? 'Unknown';
                    DB::table('project_status')->where('id', $row->id)->update(['title' => $title]);
                }
            });

        // Drop status_code column
        Schema::table('project_status', function (Blueprint $table) {
            $table->dropColumn('status_code');
        });
    }
};
