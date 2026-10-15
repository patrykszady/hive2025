<?php

namespace App\Services;

use App\Models\CheckImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Stage 2 of the check-image pipeline: the per-check Content Understanding
 * analyzer (services.azure_cu.analyzer_id_check) reads one cropped check
 * front — payer block, number, date, payee, amount, MICR — onto its row.
 * Shared by `cu:process-check-images` and a check image uploaded by hand.
 */
class CheckImageAnalyzer
{
    public function __construct(protected ContentUnderstandingService $cu) {}

    /**
     * @throws \Throwable when the analyzer call fails
     */
    public function analyze(CheckImage $image): void
    {
        $analyzerId = config('services.azure_cu.analyzer_id_check');
        $raw = $this->cu->analyzeBinary(Storage::disk('files')->get($image->file_path), $analyzerId, 'check_images');
        $fields = ContentUnderstandingService::normalizeFieldValues($raw['result']['contents'][0]['fields'] ?? []);

        $micrAccount = preg_replace('/\D/', '', (string) ($fields['BankInfo']['AccountNumber'] ?? ''));

        // The statement caption (or the bank's own header) is authoritative
        // for the number and amount; flag drift.
        $bankNumber = $fields['CheckNumber']['Bank'] ?? null;
        if ($bankNumber !== null && $image->check_number !== null && (int) $bankNumber !== (int) $image->check_number) {
            Log::channel('check_images')->warning('Analyzer check number disagrees with statement caption', [
                'image' => $image->image_filename,
                'statement' => $image->check_number,
                'analyzer' => $bankNumber,
            ]);
        }

        $image->update([
            'check_fields' => $fields,
            'payee' => $fields['CheckInfo']['Payee'] ?? null,
            'account_number' => $micrAccount !== '' ? $micrAccount : $image->account_number,
            'analyzer_id' => $analyzerId,
            'analyzed_at' => now(),
        ]);
    }
}
