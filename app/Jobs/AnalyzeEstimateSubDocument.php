<?php

namespace App\Jobs;

use App\Models\EstimateSubDocument;
use App\Models\EstimateSubDocumentItem;
use App\Services\Estimates\SubDocumentMatcher;
use App\Services\Estimates\SubDocumentReader;
use App\Services\Estimates\SubVendorRecogniser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Reads a sub's uploaded estimate/invoice and matches it against our
 * estimate (two model calls that take longer than a web request should),
 * leaving the proposed change-order rows on the EstimateSubDocument for the
 * admin to review. The sub is recognised from the document's company name
 * (SubVendorRecogniser: the project's bidders first, then the directory).
 */
class AnalyzeEstimateSubDocument implements ShouldQueue
{
    use Queueable;

    public int $timeout = 420;

    public int $tries = 1;

    public function __construct(public int $estimateSubDocumentId)
    {
        $this->onQueue('default');
    }

    public function handle(SubDocumentReader $reader, SubDocumentMatcher $matcher, ?SubVendorRecogniser $recogniser = null): void
    {
        $recogniser ??= app(SubVendorRecogniser::class);
        $document = EstimateSubDocument::find($this->estimateSubDocumentId);

        if (! $document || $document->status !== EstimateSubDocument::STATUS_EXTRACTING) {
            return;
        }

        try {
            $path = Storage::disk($document->file_disk)->path($document->file_path);
            $read = $reader->read($path, (string) $document->mime_type, $document->original_name);
            $document->update([
                'document' => $read,
                'model' => $read['model'],
                'sub_vendor_id' => $document->sub_vendor_id ?: $recogniser->recognise($document->project_id, $read['vendor_name']),
            ]);

            $matched = $matcher->match($document->estimate, $read, $document->sub_vendor_id);

            // Every line kept on its own row: the sub's price and scope history.
            $document->items()->delete();
            foreach ($read['items'] as $item) {
                $assignment = $matched['assignments'][$item['index']] ?? null;
                EstimateSubDocumentItem::create([
                    'estimate_sub_document_id' => $document->id,
                    'belongs_to_vendor_id' => $document->belongs_to_vendor_id,
                    'sub_vendor_id' => $document->sub_vendor_id,
                    'project_id' => $document->project_id,
                    'index' => $item['index'],
                    'description' => mb_substr($item['description'], 0, 500),
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'] ? mb_substr($item['unit'], 0, 40) : null,
                    'unit_price' => $item['unit_price'],
                    'amount' => $item['amount'],
                    'line_item_id' => $assignment['line_item_id'] ?? null,
                    'match_source' => $assignment === null || $assignment['line_item_id'] === null ? null : (($assignment['learned'] ?? false) ? 'alias' : 'model'),
                    'document_date' => $read['document_date'],
                ]);
            }

            $document->update([
                'proposals' => ['trades' => $matched['trades'], 'rows' => $matched['rows']],
                'status' => EstimateSubDocument::STATUS_MATCHED,
            ]);
        } catch (\Throwable $e) {
            Log::error('Sub document analysis failed', ['estimate_sub_document_id' => $document->id, 'error' => $e->getMessage()]);
            $document->update(['status' => EstimateSubDocument::STATUS_FAILED, 'failure_reason' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }
}
