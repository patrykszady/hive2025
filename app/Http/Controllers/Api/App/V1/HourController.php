<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\SaveHoursRequest;
use App\Models\Hour;
use App\Services\Hours\AppHoursDay;
use App\Services\Hours\HourEntryWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Hours tab: the same per-day, per-project hour logging as the
 * web's HourCreate — see App\Services\Hours\AppHoursDay for the shared
 * "which projects are offered today" query and App\Services\Hours\HourEntryWriter
 * for the shared write (also used by the web's HourForm), so logging hours
 * behaves identically from either client.
 */
class HourController extends Controller
{
    public function day(Request $request, AppHoursDay $hoursDay): JsonResponse
    {
        $this->authorize('create', Hour::class);

        $date = $this->validatedDate($request);

        return response()->json(['data' => $hoursDay->dayPayload($request->user(), $date)]);
    }

    /**
     * Projects not already offered for $date, for the "log a different
     * project" picker.
     */
    public function otherProjects(Request $request, AppHoursDay $hoursDay): JsonResponse
    {
        $this->authorize('create', Hour::class);

        $date = $this->validatedDate($request);
        $offered = collect($hoursDay->dayPayload($request->user(), $date)['projects'])->pluck('id')->all();

        return response()->json(['data' => $hoursDay->otherProjects($offered)]);
    }

    /**
     * Saves the whole day at once: the controller looks up each project's
     * existing Hour row itself (rather than trusting a client-held id), then
     * hands every entry to HourEntryWriter::update() — the same write
     * HourForm uses for both "Add" and "Edit", since a missing existing row
     * is simply created there.
     */
    public function store(SaveHoursRequest $request, HourEntryWriter $writer): JsonResponse
    {
        $user = $request->user();
        $date = $request->validated('date');

        $existingByProject = Hour::where('user_id', $user->id)->where('date', $date)->get()->keyBy('project_id');

        $prepared = collect($request->validated('entries'))
            ->map(function (array $entry) use ($existingByProject): array {
                $existing = $existingByProject->get((int) $entry['project_id']);

                return [
                    'id' => $entry['project_id'],
                    'hours' => $entry['hours'] ?? null,
                    'hour_id' => $existing?->id,
                ];
            })
            ->all();

        $writer->update($user, $date, $prepared);

        return response()->json(['data' => app(AppHoursDay::class)->dayPayload($user, $date)]);
    }

    private function validatedDate(Request $request): string
    {
        $date = (string) $request->query('date', '');

        abort_unless((bool) \DateTime::createFromFormat('!Y-m-d', $date), 422, 'A valid date is required.');

        return $date;
    }
}
