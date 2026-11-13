<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/estimates/{estimate}/ai-runs — "Generate Estimate" in the
 * Hive Contractors app: the description, the section rows the estimator
 * ticked (App\Services\EstimateAI\EstimateDraftPlanner reads them exactly as
 * it reads the website's), and the plans attached. With plans the request is
 * multipart, and the rows arrive as one JSON-encoded string.
 * Real authorization ('update' on the estimate) happens in the controller.
 */
class StartEstimateAiRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $sections = $this->input('sections');

        if (is_string($sections)) {
            $decoded = json_decode($sections, true);
            $this->merge(['sections' => is_array($decoded) ? $decoded : []]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'inquiry' => ['required', 'string', 'min:10', 'max:20000'],
            'sections' => ['nullable', 'array', 'max:80'],
            'sections.*.key' => ['required', 'string', 'max:80', 'distinct'],
            'sections.*.name' => ['nullable', 'string', 'max:60'],
            'sections.*.section_id' => ['nullable', 'integer'],
            'sections.*.include' => ['nullable', 'boolean'],
            'sections.*.note' => ['nullable', 'string', 'max:300'],
            'sections.*.merged_into' => ['nullable', 'string', 'max:80'],
            'reuse_run_id' => ['nullable', 'integer'],
            'floorplans' => ['nullable', 'array', 'max:6'],
            'floorplans.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp,gif,bmp,csv,txt', 'max:20480'],
            'labels' => ['nullable', 'array', 'max:6'],
            'labels.*' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'inquiry.required' => 'Describe the work to draft.',
            'inquiry.min' => 'Describe the work in a little more detail.',
            'sections.*.name.max' => 'A section name can be 60 characters at most.',
            'sections.*.note.max' => 'A section note can be 300 characters at most.',
            'floorplans.max' => 'Attach six plans at most.',
            'floorplans.*.mimes' => 'A plan must be a picture, a PDF or a room scan (CSV).',
            'floorplans.*.max' => 'Each plan must be under 20 MB.',
        ];
    }

    /**
     * The rows as the planner reads them. A row is ticked unless it says
     * otherwise.
     *
     * @return list<array{key: string, name: string, include: bool, section_id: ?int, merged_into: ?string, note: string}>
     */
    public function rows(): array
    {
        return array_values(array_map(fn (array $row) => [
            'key' => (string) $row['key'],
            'name' => (string) ($row['name'] ?? ''),
            'include' => ! array_key_exists('include', $row) || $row['include'] === null || filter_var($row['include'], FILTER_VALIDATE_BOOLEAN),
            'section_id' => empty($row['section_id']) ? null : (int) $row['section_id'],
            'merged_into' => filled($row['merged_into'] ?? null) ? (string) $row['merged_into'] : null,
            'note' => (string) ($row['note'] ?? ''),
        ], (array) ($this->validated('sections') ?? [])));
    }
}
