<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Models\VendorDoc;
use App\Services\Agents\AgentBusinessGrouping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * The app's Agents screen — Patryk (user id 1) only, exactly as the web's
 * /agents page (App\Livewire\Agents\AgentsIndex), via
 * App\Services\Agents\AgentBusinessGrouping.
 */
class AgentController extends Controller
{
    public function __construct(private AgentBusinessGrouping $grouping)
    {
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->id === 1, 403);

        $groups = $this->grouping->grouped()->map(fn (object $group) => [
            'business_name' => $group->business_name,
            'agents' => collect($group->agents)->map(fn (object $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'email' => $agent->email,
                'address' => $agent->address,
                'vendor_docs_count' => $agent->vendor_docs_count,
            ])->values()->all(),
        ])->values()->all();

        return response()->json(['data' => $groups]);
    }

    /** AgentsIndex::selectedVendorDocs() — the "View documents" modal for a business's agents. */
    public function vendorDocs(Request $request): JsonResponse
    {
        abort_unless($request->user()->id === 1, 403);

        $agentIds = array_map('intval', Arr::wrap($request->query('agent_ids', [])));

        if ($agentIds === []) {
            return response()->json(['data' => []]);
        }

        $docs = VendorDoc::withoutGlobalScopes()
            ->with('vendor')
            ->whereIn('agent_id', $agentIds)
            ->orderByDesc('expiration_date')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $docs->map(fn (VendorDoc $doc) => [
                'id' => $doc->id,
                'type' => (string) $doc->getRawOriginal('type'),
                'type_label' => $doc->type_label,
                'number' => $doc->number,
                'expiration_date' => $doc->expiration_date?->toDateString(),
                'vendor' => $doc->vendor ? ['id' => $doc->vendor->id, 'business_name' => $doc->vendor->business_name] : null,
            ])->values()->all(),
        ]);
    }
}
