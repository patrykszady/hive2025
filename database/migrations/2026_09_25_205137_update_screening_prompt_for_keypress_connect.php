<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The screening prompt now ends with "Press 5 to connect, or 1 to text them
 * back" (added in code), because staying on the line let a voicemail box
 * connect. A saved prompt that still tells people to "remain on the line to
 * connect" would contradict it, so that sentence is removed; a prompt left
 * empty falls back to the default "Call from {name}."
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('vendors')
            ->whereNotNull('options')
            ->where('options', 'like', '%remain on the line%')
            ->orderBy('id')
            ->get(['id', 'options'])
            ->each(function (object $vendor): void {
                $options = json_decode((string) $vendor->options, true);
                $message = is_array($options) ? ($options['screening_message'] ?? null) : null;

                if (! is_string($message) || stripos($message, 'remain on the line') === false) {
                    return;
                }

                $options['screening_message'] = self::withoutStayOnTheLine($message);

                DB::table('vendors')->where('id', $vendor->id)->update(['options' => json_encode($options)]);
            });
    }

    public function down(): void
    {
        // The removed sentence described the old behaviour; nothing to restore.
    }

    /**
     * Drops every sentence that mentions staying on the line or hanging up;
     * null when nothing is left.
     */
    public static function withoutStayOnTheLine(string $message): ?string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($message)) ?: [];

        $kept = array_filter($sentences, fn (string $sentence): bool => ! preg_match('/remain on the line|stay on the line|hang up/i', $sentence));

        $result = trim(implode(' ', $kept));

        return $result === '' ? null : $result;
    }
};
