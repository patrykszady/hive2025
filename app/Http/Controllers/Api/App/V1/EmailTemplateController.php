<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreEmailTemplateRequest;
use App\Http\Requests\Api\App\UpdateEmailTemplateRequest;
use App\Http\Resources\Api\App\EmailTemplateResource;
use App\Models\EmailTemplate;
use App\Services\EmailTemplates\AppEmailTemplateDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Email Templates screen — see
 * App\Livewire\EmailTemplates\{EmailTemplateIndex,EmailTemplateForm} (the
 * web pages this mirrors) and App\Services\EmailTemplates\
 * AppEmailTemplateDirectory.
 */
class EmailTemplateController extends Controller
{
    public function __construct(private AppEmailTemplateDirectory $directory)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EmailTemplate::class);

        $type = (string) $request->query('type', 'estimate');
        $page = $this->directory->list($type, (int) $request->query('page', 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn (EmailTemplate $template) => (new EmailTemplateResource($template))->toArray($request))->all(),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
            'placeholders' => $this->directory->placeholders($type),
        ]);
    }

    public function show(Request $request, EmailTemplate $template): JsonResponse
    {
        $this->authorize('view', $template);

        return response()->json(['data' => (new EmailTemplateResource($template))->toArray($request)]);
    }

    public function store(StoreEmailTemplateRequest $request): JsonResponse
    {
        $template = $this->directory->create((int) $request->user()->primary_vendor_id, $request->validated());

        return response()->json(['data' => (new EmailTemplateResource($template))->toArray($request)], 201);
    }

    public function update(UpdateEmailTemplateRequest $request, EmailTemplate $template): JsonResponse
    {
        $updated = $this->directory->update($template, $request->validated());

        return response()->json(['data' => (new EmailTemplateResource($updated))->toArray($request)]);
    }

    public function destroy(Request $request, EmailTemplate $template): JsonResponse
    {
        $this->authorize('delete', $template);

        $template->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }
}
