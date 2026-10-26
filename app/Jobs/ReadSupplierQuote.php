<?php

namespace App\Jobs;

use App\Models\EstimateSubDocument;
use App\Models\EstimateSubDocumentItem;
use App\Services\Estimates\SubVendorRecogniser;
use App\Services\Estimates\SupplierQuoteReader;
use App\Services\Estimates\SupplierQuoteSectionWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Reads a window and door supplier's quote (one model call, longer than a
 * web request should wait) into the EstimateSubDocument's `document`, so the
 * admin can review the schedule with its drawings and add it to the estimate
 * (App\Livewire\Estimates\EstimateSupplierQuote). The supplier is recognised
 * from the company name on the document when the admin did not pick one.
 */
class ReadSupplierQuote implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $estimateSubDocumentId)
    {
        $this->onQueue('default');
    }

    public function handle(SupplierQuoteReader $reader, SubVendorRecogniser $recogniser): void
    {
        $document = EstimateSubDocument::find($this->estimateSubDocumentId);

        if (! $document || $document->kind !== EstimateSubDocument::KIND_QUOTE || $document->status !== EstimateSubDocument::STATUS_EXTRACTING) {
            return;
        }

        try {
            $path = Storage::disk($document->file_disk)->path($document->file_path);
            $read = $reader->readQuote($path, (string) $document->mime_type, $document->original_name);

            if ($read['items'] === []) {
                throw new RuntimeException('No window or door lines were found on the document.');
            }

            $document->update([
                'document' => $read,
                'model' => $read['model'],
                'sub_vendor_id' => $document->sub_vendor_id ?: $recogniser->recognise($document->project_id, $read['vendor_name']),
            ]);

            // Every unit on its own row: the supplier's price history per size and type.
            $document->items()->delete();
            foreach ($read['items'] as $item) {
                EstimateSubDocumentItem::create([
                    'estimate_sub_document_id' => $document->id,
                    'belongs_to_vendor_id' => $document->belongs_to_vendor_id,
                    'sub_vendor_id' => $document->sub_vendor_id,
                    'project_id' => $document->project_id,
                    'index' => $item['index'],
                    'description' => mb_substr(SupplierQuoteSectionWriter::lineName($item), 0, 500),
                    'quantity' => $item['quantity'],
                    'unit' => 'pieces',
                    'unit_price' => $item['unit_price'],
                    'amount' => $item['amount'],
                    'document_date' => $read['document_date'],
                ]);
            }

            $document->update(['status' => EstimateSubDocument::STATUS_MATCHED]);
        } catch (\Throwable $e) {
            Log::error('Supplier quote reading failed', ['estimate_sub_document_id' => $document->id, 'error' => $e->getMessage()]);
            $document->update(['status' => EstimateSubDocument::STATUS_FAILED, 'failure_reason' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }
}
