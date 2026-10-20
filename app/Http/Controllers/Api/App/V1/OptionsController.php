<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\UpdateVendorOptionsRequest;
use App\Http\Requests\Api\App\UploadVendorLogoRequest;
use App\Models\Vendor;
use App\Services\Vendors\AppVendorOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Options screen — the same fields and save rules as the web's
 * /options page (App\Livewire\Vendors\VendorOptions), via
 * App\Services\Vendors\AppVendorOptions.
 */
class OptionsController extends Controller
{
    public function __construct(private AppVendorOptions $options)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewOptions', Vendor::class);

        return response()->json(['data' => $this->options->present($request->user()->vendor)]);
    }

    public function update(UpdateVendorOptionsRequest $request): JsonResponse
    {
        $vendor = $this->options->update($request->user()->vendor, $request->validated());

        return response()->json(['data' => $this->options->present($vendor->refresh())]);
    }

    public function uploadLogo(UploadVendorLogoRequest $request): JsonResponse
    {
        $vendor = $this->options->uploadLogo($request->user()->vendor, $request->file('logo'));

        return response()->json(['data' => $this->options->present($vendor->refresh())]);
    }

    public function destroyLogo(Request $request): JsonResponse
    {
        $this->authorize('viewOptions', Vendor::class);

        $vendor = $this->options->removeLogo($request->user()->vendor);

        return response()->json(['data' => $this->options->present($vendor->refresh())]);
    }
}
