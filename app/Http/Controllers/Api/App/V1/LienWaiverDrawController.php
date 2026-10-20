<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Exceptions\LienWaivers\DrawAlreadySignedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreDrawRequest;
use App\Http\Requests\Api\App\UpdateDrawRequest;
use App\Http\Resources\Api\App\SwornStatementResource;
use App\Models\LienWaiver;
use App\Models\Project;
use App\Models\SwornStatement;
use App\Services\LienWaivers\DrawPackageService;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's GCSS sworn statement / draw package flow — mirrors
 * App\Livewire\LienWaivers\Index's openSwornStatement()/editDraw()/
 * generateSwornStatement()/deleteSwornStatement() exactly, via the shared
 * App\Services\LienWaivers\DrawPackageService both the web component and
 * this controller write through. Admin-only, same gate as the rest of
 * Lien Waivers (LienWaiverPolicy's viewAny/create require vendor_role
 * 'Admin'); delete has no separate web-side policy call either (the web
 * component only scopes by belongs_to_vendor_id) but this controller still
 * requires 'create' for every write, same defense-in-depth the earlier
 * lien-waivers parity round added to index()/store().
 */
class LienWaiverDrawController extends Controller
{
    public function __construct(protected DrawPackageService $draws)
    {
    }

    /** Stored draws for this tenant, optionally scoped to one project — mirrors Index::swornStatements(). */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LienWaiver::class);

        $contractor = $request->user()->vendor;
        abort_unless($contractor, 422, 'Your account is missing a vendor association.');

        $projectId = $request->query('project_id');
        $project = is_numeric($projectId) ? Project::query()->find($projectId) : null;

        $statements = $this->draws->swornStatements($contractor, $project);

        return response()->json([
            'data' => $statements->map(fn (SwornStatement $statement) => (new SwornStatementResource($statement))->toArray($request))->values()->all(),
        ]);
    }

    /** One draw's detail (statement + the waivers it created) — mirrors the project card's drawGroups() entry. */
    public function show(Request $request, int $swornStatement): JsonResponse
    {
        $this->authorize('viewAny', LienWaiver::class);

        $statement = $this->ownedStatement($request, $swornStatement);

        return response()->json(['data' => (new SwornStatementResource($statement))->withDetail()->toArray($request)]);
    }

    /** Rows for a brand-new draw on a project — mirrors Index::openSwornStatement(). */
    public function new(Request $request): JsonResponse
    {
        $this->authorize('create', LienWaiver::class);

        $contractor = $request->user()->vendor;
        abort_unless($contractor, 422, 'Your account is missing a vendor association.');

        $project = Project::query()->find($request->query('project_id'));
        abort_unless($project, 404);

        return response()->json(['data' => [
            'project' => ['id' => $project->id, 'name' => $project->project_name, 'address' => $project->short_address],
            'has_signed_contract' => $this->draws->hasSignedContract($project, $contractor),
            'rows' => $this->draws->newDrawRows($project, $contractor),
            'kind_of_work_options' => $this->draws->kindOfWorkOptions($contractor),
        ]]);
    }

    /** Rows to reopen an existing draw for editing — mirrors Index::editDraw(). */
    public function edit(Request $request, int $swornStatement): JsonResponse
    {
        $this->authorize('create', LienWaiver::class);

        $contractor = $request->user()->vendor;
        $statement = $this->ownedStatement($request, $swornStatement);
        $project = Project::withoutGlobalScopes()->findOrFail($statement->project_id);

        try {
            $draw = $this->draws->editableDraw($statement, $project, $contractor);
        } catch (DrawAlreadySignedException $e) {
            return response()->json([
                'message' => 'Draw '.$e->drawNumber().' has a signed sworn statement and can no longer be edited.',
            ], 422);
        }

        return response()->json(['data' => [
            'statement' => (new SwornStatementResource($statement))->toArray($request),
            'rows' => $draw['rows'],
            'this_payment' => $draw['this_payment'],
            'kind_of_work_options' => $this->draws->kindOfWorkOptions($contractor),
        ]]);
    }

    /** Create the full draw package — mirrors Index::generateSwornStatement()'s create branch. */
    public function store(StoreDrawRequest $request): JsonResponse
    {
        $this->authorize('create', LienWaiver::class);

        $contractor = $request->user()->vendor;
        abort_unless($contractor, 422, 'Your account is missing a vendor association.');

        $project = Project::query()->find($request->validated('project_id'));
        abort_unless($project, 404);

        [$rows, $thisPayment] = $this->draws->normalize($request->validated('rows'), (string) $request->validated('this_payment'));

        $validator = $this->draws->buildValidator($rows, $thisPayment);

        if ($validator->fails()) {
            return $this->validationFailedResponse($validator);
        }

        $result = $this->draws->createDraw($project, $contractor, $rows, (float) $thisPayment, $request->user());

        return response()->json(['data' => [
            'statement' => (new SwornStatementResource($result['statement']))->withDetail()->toArray($request),
            'gc_waiver_created' => $result['gc_waiver_created'],
            'sub_sent' => $result['sub_sent'],
            'sub_printed' => $result['sub_printed'],
            'sub_reused' => $result['sub_reused'],
            'saved_bids' => $result['saved_bids'],
            'emailed_producer' => $result['emailed_producer'],
        ]], 201);
    }

    /** Apply edits to an existing draw — mirrors Index::generateSwornStatement()'s update branch (updateSwornStatement()). */
    public function update(UpdateDrawRequest $request, int $swornStatement): JsonResponse
    {
        $this->authorize('create', LienWaiver::class);

        $contractor = $request->user()->vendor;
        $statement = $this->ownedStatement($request, $swornStatement);
        $project = Project::withoutGlobalScopes()->findOrFail($statement->project_id);

        [$rows, $thisPayment] = $this->draws->normalize($request->validated('rows'), (string) $request->validated('this_payment'));

        $validator = $this->draws->buildValidator($rows, $thisPayment);

        if ($validator->fails()) {
            return $this->validationFailedResponse($validator);
        }

        try {
            $result = $this->draws->updateDraw($statement, $project, $contractor, $rows, (float) $thisPayment, $request->user());
        } catch (DrawAlreadySignedException $e) {
            return response()->json([
                'message' => 'Draw '.$e->drawNumber().' was signed while you were editing — no changes saved.',
            ], 422);
        }

        return response()->json(['data' => [
            'statement' => (new SwornStatementResource($result['statement']))->withDetail()->toArray($request),
            'revised' => $result['revised'],
            'added_sent' => $result['added_sent'],
            'added_printed' => $result['added_printed'],
            'added_reused' => $result['added_reused'],
            'removed' => $result['removed'],
            'frozen' => $result['frozen'],
            'saved_bids' => $result['saved_bids'],
        ]]);
    }

    /** Delete a whole draw — mirrors Index::deleteSwornStatement(). */
    public function destroy(Request $request, int $swornStatement): JsonResponse
    {
        $this->authorize('create', LienWaiver::class);

        $contractor = $request->user()->vendor;
        $statement = $this->ownedStatement($request, $swornStatement);

        $this->draws->deleteDraw($statement, $contractor);

        return response()->json(['message' => 'Draw deleted.']);
    }

    /** A draw belonging to the requesting vendor. 404 when it doesn't exist or isn't theirs. */
    protected function ownedStatement(Request $request, int $id): SwornStatement
    {
        $tenantId = (int) ($request->user()->vendor?->id ?? 0);

        $statement = SwornStatement::query()
            ->where('belongs_to_vendor_id', $tenantId)
            ->find($id);

        abort_unless($statement, 404);

        return $statement;
    }

    /**
     * The shared validator's error keys are Livewire property paths
     * (ssThisPayment, ssRows.*.kind) — remapped to the app's own field names
     * so the JSON response reads like every other endpoint's 422.
     */
    protected function validationFailedResponse(ValidatorContract $validator): JsonResponse
    {
        $errors = [];

        foreach ($validator->errors()->messages() as $key => $messages) {
            $mappedKey = $key === 'ssThisPayment' ? 'this_payment' : preg_replace('/^ssRows\./', 'rows.', $key);
            $errors[$mappedKey] = $messages;
        }

        return response()->json(['message' => 'The given data was invalid.', 'errors' => $errors], 422);
    }
}
