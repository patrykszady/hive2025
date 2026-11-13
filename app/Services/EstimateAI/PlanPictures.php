<?php

namespace App\Services\EstimateAI;

use App\Models\EstimateAiRun;
use App\Services\Estimates\FloorplanParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The layouts handed to a whole-estimate draft, split by how long each takes
 * to read. A room scan (CSV) is read on the spot. A picture or a PDF has to
 * be sent off to be read — up to a minute each — so it is put aside here when
 * Generate is clicked and read by the background job that drafts the
 * estimate (2026-11-13: reading them inside the click held the page for up
 * to 20 seconds a file, against production's 60-second limit on a request).
 *
 * What waits is kept on the run (`plan.plans`), every layout in the order
 * it was uploaded so the floors add up the same either way; the files
 * themselves sit on the private disk only until the job has read them.
 */
class PlanPictures
{
    private const DISK = 'local';

    private const FOLDER = 'estimate-ai-plans';

    public function __construct(private FloorplanParser $parser) {}

    /**
     * Take the uploaded layouts in: scans read now, pictures and PDFs put
     * aside for the job.
     *
     * @param  list<UploadedFile>  $files
     * @param  list<string>  $labels  A name per file, same position ("Floor 1", "Basement").
     * @return array{floorplan: ?array<string, mixed>, waiting: list<array<string, mixed>>}  what could be read now, and — only when something waits — every layout for the job to finish reading
     */
    public function take(array $files, array $labels): array
    {
        $labels = array_values($labels);
        $folder = self::FOLDER.'/'.Str::uuid();
        $layouts = [];
        $waiting = false;

        foreach (array_values($files) as $index => $file) {
            $label = trim((string) ($labels[$index] ?? '')) ?: 'Floor '.($index + 1);

            if ($this->parser->isRoomScan($file)) {
                $data = $this->parser->parseFloorplanFile($file);

                if ($data !== null) {
                    $layouts[] = ['label' => $label, 'data' => $data];
                }

                continue;
            }

            $extension = strtolower($file->getClientOriginalExtension()) ?: 'bin';
            $layouts[] = [
                'label' => $label,
                'path' => $file->storeAs($folder, $index.'.'.preg_replace('/[^a-z0-9]/', '', $extension), self::DISK),
                'filename' => $file->getClientOriginalName(),
            ];
            $waiting = true;
        }

        return [
            'floorplan' => self::asOne(array_values(array_filter($layouts, fn (array $layout) => isset($layout['data'])))),
            'waiting' => $waiting ? $layouts : [],
        ];
    }

    /**
     * The job's side: read what was put aside and give back every layout as
     * one, exactly as if all of it had been read at the click.
     *
     * @param  list<array<string, mixed>>  $layouts  as take() left them in `waiting`
     * @param  (callable(): void)|null  $afterEach  called after each file is read — a sign of life, a look for a stop
     * @return array<string, mixed>|null
     */
    public function read(array $layouts, ?callable $afterEach = null): ?array
    {
        $read = [];

        foreach ($layouts as $layout) {
            if (isset($layout['data'])) {
                $read[] = ['label' => (string) $layout['label'], 'data' => (array) $layout['data']];

                continue;
            }

            $filename = (string) ($layout['filename'] ?? 'plan');
            $contents = filled($layout['path'] ?? null) ? Storage::disk(self::DISK)->get($layout['path']) : null;

            $read[] = [
                'label' => (string) $layout['label'],
                'data' => $contents === null
                    ? ['filename' => $filename, 'type' => pathinfo($filename, PATHINFO_EXTENSION), 'note' => 'The plan could not be read. Please verify manually.']
                    : $this->parser->readPicture($contents, $filename, FloorplanParser::READ_SECONDS_IN_JOB),
            ];

            if ($afterEach !== null) {
                $afterEach();
            }
        }

        return self::asOne($read);
    }

    /**
     * The files put aside are no longer needed: read, or their run ended
     * before it got to them.
     *
     * @param  list<array<string, mixed>>  $layouts
     */
    public function forget(array $layouts): void
    {
        collect($layouts)
            ->pluck('path')
            ->filter(fn ($path) => is_string($path) && str_starts_with($path, self::FOLDER.'/'))
            ->map(fn (string $path) => dirname($path))
            ->unique()
            ->each(fn (string $folder) => Storage::disk(self::DISK)->deleteDirectory($folder));
    }

    /** A run that ended without reading its plans — never taken by a worker, or failed first — leaves no files behind. */
    public function forgetFor(?EstimateAiRun $run): void
    {
        $waiting = (array) ($run?->plan['plans'] ?? []);

        if ($waiting !== []) {
            $this->forget($waiting);
        }
    }

    /**
     * @param  list<array{label: string, data: array<string, mixed>}>  $layouts
     * @return array<string, mixed>|null
     */
    private static function asOne(array $layouts): ?array
    {
        return match (count($layouts)) {
            0 => null,
            1 => $layouts[0]['data'],
            default => FloorplanParser::combineFloorplans($layouts),
        };
    }
}
