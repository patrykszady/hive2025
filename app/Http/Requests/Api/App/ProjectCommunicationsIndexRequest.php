<?php

namespace App\Http\Requests\Api\App;

use App\Services\Communications\ProjectCommunicationsTimeline;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/app/v1/projects/{project}/communications — `before` pages
 * backwards through ProjectCommunicationsTimeline's merged call/text/email
 * list, `kinds[]` narrows it to a subset of
 * ProjectCommunicationsTimeline::KINDS, and `limit` is capped the same way
 * the service itself caps it (1-100).
 */
class ProjectCommunicationsIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'before' => ['nullable', 'date'],
            'kinds' => ['nullable', 'array'],
            'kinds.*' => ['string', Rule::in(ProjectCommunicationsTimeline::KINDS)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
