<?php

namespace App\Http\Resources\Api\App;

use App\Enums\LienWaiverStatus;
use App\Models\LienWaiver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * A GCSS draw (App\Models\SwornStatement) as the app's Lien Waivers tab
 * shows it — same numbering, amount and status as the web's project card
 * and standalone-page GCSS row (App\Livewire\LienWaivers\Index::drawGroups()/
 * swornStatements()). With withDetail() it adds the waivers the draw
 * created, for the draw detail screen.
 *
 * @mixin \App\Models\SwornStatement
 */
class SwornStatementResource extends JsonResource
{
    protected bool $detail = false;

    public function withDetail(): static
    {
        $this->detail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $project = $this->resource->project;

        $waivers = LienWaiver::withoutGlobalScopes()
            ->where('sworn_statement_id', $this->id)
            ->where('belongs_to_vendor_id', $this->belongs_to_vendor_id)
            ->whereNull('deleted_at')
            ->when($this->detail, fn ($q) => $q->with(['vendor', 'payerVendor', 'project.client', 'check', 'payment']))
            ->orderByRaw('vendor_id = ? DESC', [(int) $this->belongs_to_vendor_id])
            ->orderBy('vendor_id')
            ->get(['id', 'vendor_id', 'status']);

        $row = [
            'id' => $this->id,
            'draw_number' => $this->resource->drawNumber(),
            'status' => [
                'value' => $this->status?->value,
                'label' => $this->status?->label(),
                'short_label' => $this->status?->name,
                'color' => $this->status?->color(),
            ],
            'this_payment' => (float) $this->this_payment,
            'created_at' => $this->created_at?->toIso8601String(),
            'project' => $project ? ['id' => $project->id, 'name' => $project->project_name, 'address' => $project->short_address] : null,
            'waiver_counts' => [
                'total' => $waivers->count(),
                'sent' => $waivers->where('status', LienWaiverStatus::Sent)->count(),
                'signed' => $waivers->where('status', LienWaiverStatus::Signed)->count(),
            ],
            'can_edit' => $this->status?->value !== LienWaiverStatus::Signed->value,
            'pdf_url' => URL::temporarySignedRoute('sworn-statements.download.signed', now()->addHours(24), ['swornStatement' => $this->id]),
            'package_pdf_url' => URL::temporarySignedRoute('sworn-statements.download-package.signed', now()->addHours(24), ['swornStatement' => $this->id]),
        ];

        if (! $this->detail) {
            return $row;
        }

        return $row + [
            'waivers' => $waivers->map(fn (LienWaiver $waiver) => (new LienWaiverResource($waiver))->withDetail()->toArray($request))->values()->all(),
        ];
    }
}
