<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\AutocompleteAddressRequest;
use App\Http\Requests\Api\App\ResolveAddressRequest;
use App\Services\GeoapifyService;
use Illuminate\Http\JsonResponse;

/**
 * Address autocomplete for every app form with an address field — the
 * exact same provider, bias and filters the web's address fields use via
 * App\Traits\HandlesAddresses (see App\Services\GeoapifyService). Two
 * steps, mirroring the trait exactly: `autocomplete` lists candidates as
 * the person types (updatedAddressQuery), `resolve` turns the chosen one's
 * place_id into full address fields (selectSuggestion/getPlaceDetails).
 * Both are reusable across forms, so this file is not tied to any one
 * domain's route file.
 */
class AddressController extends Controller
{
    public function __construct(private GeoapifyService $geoapify)
    {
    }

    /**
     * @return JsonResponse `data`: list<array{place_id: string, description: string}>
     */
    public function autocomplete(AutocompleteAddressRequest $request): JsonResponse
    {
        $suggestions = $this->geoapify->getAutocompleteSuggestions($request->string('query')->value());

        return response()->json(['data' => $suggestions]);
    }

    /**
     * @return JsonResponse `data`: array{place_id: string, line1: string, city: ?string, state: ?string, zip: ?string}|null
     */
    public function resolve(ResolveAddressRequest $request): JsonResponse
    {
        $details = $this->geoapify->getPlaceDetails($request->string('place_id')->value());

        if (empty($details)) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'place_id' => $details['place_id'],
                'line1' => trim(($details['street_number'] ?? '').' '.($details['route'] ?? '')),
                'city' => $details['locality'] ?: null,
                'state' => $details['administrative_area_level_1'] ?: null,
                'zip' => $details['postal_code'] ?: null,
            ],
        ]);
    }
}
