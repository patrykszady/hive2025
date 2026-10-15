<?php

namespace App\Services;

use App\Models\Check;
use App\Models\CheckImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * A check image added by hand on a check's page: a photo of the check, or
 * the bank's single-check PDF (CheckFrontCropper cuts its front out). The
 * image is stored beside the statement crops (storage/files/checks), linked
 * to the check as a manual match so re-matching never moves it, read by the
 * same per-check analyzer as the statement crops, and its payee resolved.
 */
class CheckImageUpload
{
    public function __construct(
        protected CheckFrontCropper $cropper,
        protected CheckImageAnalyzer $analyzer,
        protected CheckImagePayeeResolver $payees,
    ) {}

    /**
     * @throws ValidationException when a PDF holds no check front to cut out
     */
    public function attach(Check $check, UploadedFile $file, string $field = 'file'): CheckImage
    {
        $content = (string) file_get_contents($file->getRealPath());
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->extension());
        $details = ['account' => null, 'check_number' => null, 'posted_on' => null, 'amount' => null];
        $statementFilename = null;

        if ($extension === 'pdf' || str_starts_with(ltrim($content), '%PDF')) {
            $cropped = $this->cropper->fromPdf($content);
            if ($cropped === null) {
                throw ValidationException::withMessages([$field => 'No check was found in that PDF. Upload the bank\'s check image PDF or a photo of the check.']);
            }
            $content = $cropped['png'];
            $details = $cropped['details'];
            $extension = 'png';
            $statementFilename = 'check-'.$check->id.'-upload-'.now()->format('YmdHis').'.pdf';
            Storage::disk('files')->put('checks/files/'.$statementFilename, (string) file_get_contents($file->getRealPath()));
        }

        $number = $details['check_number'] ?? ($check->check_number ? (int) $check->check_number : null);
        $amount = $details['amount'] ?? (float) $check->amount;
        $date = $details['posted_on'] ?? $check->date?->toDateString();
        $filename = $this->uniqueFilename(implode('_', [
            $details['account'] ?? 'upload',
            $number ?? 'check'.$check->id,
            number_format((float) $amount, 2, '.', ''),
            $date ?? 'unknown',
        ]), $extension);
        Storage::disk('files')->put('checks/'.$filename, $content);

        $image = CheckImage::create([
            'image_filename' => $filename,
            'statement_filename' => $statementFilename,
            'bank_account_id' => $check->bank_account_id,
            'check_id' => $check->id,
            'belongs_to_vendor_id' => $check->belongs_to_vendor_id,
            'check_number' => $number,
            'amount' => $amount,
            'check_date' => $date,
            'account_number' => $details['account'],
            'match_status' => CheckImage::STATUS_MANUAL,
            'match_details' => ['source' => 'upload', 'uploaded_by_user_id' => auth()->id()],
            'matched_at' => now(),
        ]);

        // The image is kept whatever the analyzer says; reading it is extra.
        try {
            $this->analyzer->analyze($image);
            $this->payees->resolve($image->fresh());
        } catch (\Throwable $e) {
            Log::channel('check_images')->error('Uploaded check image could not be analyzed', [
                'image' => $filename,
                'check_id' => $check->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $image->fresh();
    }

    protected function uniqueFilename(string $base, string $extension): string
    {
        $base = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $base);
        $name = "{$base}.{$extension}";
        for ($i = 2; Storage::disk('files')->exists('checks/'.$name); $i++) {
            $name = "{$base}_{$i}.{$extension}";
        }

        return $name;
    }
}
