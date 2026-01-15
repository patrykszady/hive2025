<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Remove include_reimbursement key from estimates.options JSON column
        DB::table('estimates')
            ->whereNotNull('options')
            ->orderBy('id')
            ->chunk(100, function ($estimates) {
                foreach ($estimates as $estimate) {
                    $options = json_decode($estimate->options, true);

                    if (is_array($options) && array_key_exists('include_reimbursement', $options)) {
                        unset($options['include_reimbursement']);

                        DB::table('estimates')
                            ->where('id', $estimate->id)
                            ->update(['options' => json_encode($options)]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reversal needed - the key is deprecated
    }
};
