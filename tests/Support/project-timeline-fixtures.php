<?php

/**
 * Helpers shared by the project timeline tests: a job that holds a booked
 * week, and readers for one calendar day's tile. require_once this file
 * rather than declaring these names twice.
 */

use App\Models\Estimate;
use App\Models\Project;
use App\Models\ProjectStatus;

if (! function_exists('timelineJob')) {
    /**
     * A job for the booked-week tests: one status row (the observer's default
     * row removed) and, when given, an estimate carrying its start date.
     */
    function timelineJob(int $vendorId, int $statusCode, ?string $estimateStart, string $statusDate = '2026-01-05'): Project
    {
        $project = Project::create([
            'project_name' => 'Timeline Job '.uniqid(),
            'client_id' => 1,
            'address' => '123 Main St',
            'city' => 'Chicago',
            'state' => 'IL',
            'zip_code' => 60601,
        ]);

        ProjectStatus::withoutGlobalScopes()->where('project_id', $project->id)->delete();
        ProjectStatus::create([
            'project_id' => $project->id,
            'belongs_to_vendor_id' => $vendorId,
            'status_code' => $statusCode,
            'start_date' => $statusDate,
        ]);

        if ($estimateStart) {
            Estimate::create([
                'project_id' => $project->id,
                'belongs_to_vendor_id' => $vendorId,
                'options' => ['start_date' => $estimateStart],
            ]);
        }

        return $project;
    }
}

if (! function_exists('timelineCell')) {
    /**
     * @return array{tone: string, limited: bool}
     */
    function timelineCell(string $html, string $date): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        $button = (new DOMXPath($dom))->query('//button[@data-date="'.$date.'"]')->item(0);

        return ['tone' => $button->getAttribute('data-tone'), 'limited' => $button->hasAttribute('data-limited')];
    }
}

if (! function_exists('timelineFade')) {
    function timelineFade(string $html, string $date): ?string
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        $button = (new DOMXPath($dom))->query('//button[@data-date="'.$date.'"]')->item(0);

        return $button->hasAttribute('data-fade') ? $button->getAttribute('data-fade') : null;
    }
}

if (! function_exists('timelineClass')) {
    function timelineClass(string $html, string $date): string
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        return (new DOMXPath($dom))->query('//button[@data-date="'.$date.'"]')->item(0)->getAttribute('class');
    }
}
