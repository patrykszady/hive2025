<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\UpdatePhoneSystemRequest;
use App\Models\Vendor;
use App\Services\Vendors\AppPhoneSystemOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Phone System screen — see
 * App\Livewire\Vendors\PhoneSystemOptions (the web page this mirrors) and
 * App\Services\Vendors\AppPhoneSystemOptions.
 */
class PhoneSystemController extends Controller
{
    public function __construct(private AppPhoneSystemOptions $phoneSystem)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('viewOptions', Vendor::class);
        abort_unless((int) $user->primary_vendor_id === 1, 404);

        return response()->json(['data' => $this->phoneSystem->present($user->vendor)]);
    }

    public function update(UpdatePhoneSystemRequest $request): JsonResponse
    {
        $vendor = $this->phoneSystem->update($request->user()->vendor, $request->validated());

        return response()->json(['data' => $this->phoneSystem->present($vendor->refresh())]);
    }
}
