<?php

namespace App\Jobs;

use App\Models\Bid;
use App\Models\EstimateSubDocument;
use App\Models\EstimateSubDocumentItem;
use App\Models\Vendor;
use App\Services\Estimates\SubDocumentMatcher;
use App\Services\Estimates\SubDocumentReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Reads a sub's uploaded estimate/invoice and matches it against our
 * estimate (two model calls that take longer than a web request should),
 * leaving the proposed change-order rows on the EstimateSubDocument for the
 * admin to review. The sub is recognised from the document's company name
 * among the project's bidders first, then the whole vendor directory.
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

    public function handle(SubDocumentReader $reader, SubDocumentMatcher $matcher): void
    {
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
                'sub_vendor_id' => $document->sub_vendor_id ?: $this->recogniseVendor($document, $read['vendor_name']),
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

    /** The vendor whose name the document carries: a bidder on this project first. */
    protected function recogniseVendor(EstimateSubDocument $document, ?string $name): ?int
    {
        $needle = self::normalise((string) $name);

        if ($needle === '') {
            return null;
        }

        $bidders = $document->project_id
            ? Vendor::withoutGlobalScopes()->whereIn('id', Bid::withoutGlobalScopes()->where('project_id', $document->project_id)->pluck('vendor_id'))->get(['id', 'business_name'])
            : collect();

        foreach ([$bidders, Vendor::withoutGlobalScopes()->get(['id', 'business_name'])] as $vendors) {
            $match = $vendors->first(function (Vendor $vendor) use ($needle) {
                $candidate = self::normalise((string) $vendor->business_name);

                return $candidate !== '' && ($candidate === $needle || str_starts_with($needle, $candidate) || str_starts_with($candidate, $needle));
            });

            if ($match) {
                return $match->id;
            }
        }

        return null;
    }

    private static function normalise(string $name): string
    {
        $name = Str::lower(Str::ascii($name));
        $name = preg_replace('/\b(inc|incorporated|llc|l\.l\.c|ltd|limited|corp|corporation|co)\b\.?/', '', $name) ?? $name;

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $name) ?? '');
    }
}
