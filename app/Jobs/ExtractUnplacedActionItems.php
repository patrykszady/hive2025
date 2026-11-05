<?php

namespace App\Jobs;

use App\Models\CallLog;
use App\Models\EmailMessage;
use App\Services\ActionItems\ProjectActionItemExtractor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * A call or email that is about a job the model could not name: its items
 * are read anyway and wait in the planner's "Needs a project" group, where
 * a person places them (CardsIndex::placeActionItem). Patryk, 2026-11-05:
 * Jenn called about "the Abby project" — had the model missed the match,
 * "ABS delivery is Monday the 16th" would have been lost entirely.
 */
class ExtractUnplacedActionItems implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;

    public function __construct(public string $kind, public int $id)
    {
        $this->onQueue('background');
    }

    public function uniqueId(): string
    {
        return "extract_unplaced_{$this->kind}_{$this->id}";
    }

    public function handle(ProjectActionItemExtractor $extractor): void
    {
        [$line, $vendorId] = match ($this->kind) {
            'call' => $this->call($extractor),
            'email' => $this->email($extractor),
            default => [null, null],
        };

        if (! $line || ! $vendorId) {
            return;
        }

        $items = $extractor->readUnplaced($line, $this->kind, $vendorId);

        Log::channel('call_ai')->info('Unplaced items read', ['kind' => $this->kind, 'id' => $this->id, 'items' => count($items)]);
    }

    /** @return array{0: ?array, 1: ?int} */
    protected function call(ProjectActionItemExtractor $extractor): array
    {
        $call = CallLog::withoutGlobalScopes()->with('transcript')->find($this->id);
        if (! $call || $call->project_id) {
            return [null, null];
        }
        $vendorId = (int) ($call->vendor_id ?: ($call->agentUser()?->primary_vendor_id ?? 0));

        return [$extractor->callLine($call), $vendorId ?: null];
    }

    /** @return array{0: ?array, 1: ?int} */
    protected function email(ProjectActionItemExtractor $extractor): array
    {
        $email = EmailMessage::withoutGlobalScopes()->find($this->id);
        if (! $email || $email->project_id) {
            return [null, null];
        }

        return [$extractor->emailLine($email), (int) $email->belongs_to_vendor_id ?: null];
    }
}
