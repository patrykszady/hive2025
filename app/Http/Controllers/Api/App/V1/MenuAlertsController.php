<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Services\Navigation\SidebarAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Menu's alert rows — the same Bank Error / Menards Sign-in / Unplaced
 * email cluster the web sidebar shows above Home (App\Livewire\AppSidebar),
 * from the shared App\Services\Navigation\SidebarAlerts. Bank and Menards
 * alerts need Bank access there too ($canViewBanks).
 */
class MenuAlertsController extends Controller
{
    public function __invoke(Request $request, SidebarAlerts $alerts): JsonResponse
    {
        $user = $request->user();
        $canViewBanks = $user->can('viewAny', Bank::class);

        return response()->json([
            'data' => [
                'bank_errors' => $canViewBanks && $alerts->hasBankErrors($user),
                'menards_needs_login' => $canViewBanks && $alerts->menardsNeedsLogin($user),
                'unplaced_email_count' => $alerts->unplacedEmailCount($user),
            ],
        ]);
    }
}
