<?php

namespace App\Http\Controllers;

use App\Models\LienWaiver;
use App\Support\LienWaiverDocumentGenerator;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LienWaiverController extends Controller
{
    /**
     * One draw's package as a single PDF: the GCSS sworn statement followed
     * by every waiver stamped with it (GC first, then subs), freshly rendered
     * and merged with Ghostscript.
     */
    public function downloadDrawPackage(\App\Models\SwornStatement $swornStatement): BinaryFileResponse
    {
        $tenantId = (int) (auth()->user()?->vendor?->id ?? 0);
        abort_unless($tenantId && (int) $swornStatement->belongs_to_vendor_id === $tenantId, 403);

        $project = \App\Models\Project::withoutGlobalScopes()->findOrFail($swornStatement->project_id);

        $tmpDir = storage_path('app/tmp/draw-packages');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $parts = [];
        $cleanup = [];

        // 1. This draw's statement PDF — the returned notarized scan when we
        // have one, otherwise the generated original.
        $statementPath = ($swornStatement->signed_path && \Storage::disk('files')->exists($swornStatement->signed_path))
            ? $swornStatement->signed_path
            : $swornStatement->path;

        if ($statementPath && \Storage::disk('files')->exists($statementPath)) {
            $parts[] = \Storage::disk('files')->path($statementPath);
        }

        // 2. This draw's waivers — GC's own first, then subs; cancelled excluded.
        $waivers = LienWaiver::withoutGlobalScopes()
            ->where('sworn_statement_id', $swornStatement->id)
            ->where('belongs_to_vendor_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('status', '!=', \App\Enums\LienWaiverStatus::Cancelled)
            ->get()
            ->sortBy([
                fn ($a, $b) => ((int) ($a->vendor_id !== $tenantId)) <=> ((int) ($b->vendor_id !== $tenantId)),
                fn ($a, $b) => $a->vendor_id <=> $b->vendor_id,
            ]);

        foreach ($waivers as $waiver) {
            // Signed waivers ship their executed document (wet-signed
            // notarized scan or e-sign PDF), never a blank re-render.
            if ($waiver->isSigned() && $waiver->signed_path && \Storage::disk('files')->exists($waiver->signed_path)) {
                $binary = \Storage::disk('files')->get($waiver->signed_path);

                if (str_starts_with($binary, '%PDF')) {
                    $parts[] = \Storage::disk('files')->path($waiver->signed_path);

                    continue;
                }
            }

            try {
                $doc = LienWaiverDocumentGenerator::generate($waiver);
            } catch (\Throwable) {
                continue;
            }

            if (! str_starts_with($doc['binary'], '%PDF')) {
                continue;
            }

            $path = $tmpDir . '/waiver-' . $waiver->id . '-' . uniqid() . '.pdf';
            file_put_contents($path, $doc['binary']);
            $parts[] = $path;
            $cleanup[] = $path;
        }

        abort_if(empty($parts), 404, 'No documents to download for this project.');

        $estimateNumber = (string) ($project->estimates?->first()?->number ?? '');
        $owner = $project->client()->withoutGlobalScopes()->first();

        $filename = collect([
            'draw-package',
            $estimateNumber !== '' ? \Illuminate\Support\Str::slug($estimateNumber) : null,
            \Illuminate\Support\Str::slug((string) ($owner?->name ?? '')),
            \Illuminate\Support\Str::slug((string) ($project->address ?? $project->project_name ?? 'project')) ?: 'project',
            'draw-' . $swornStatement->drawNumber(),
            optional($swornStatement->created_at)->format('Y-m-d') ?? now()->format('Y-m-d'),
        ])->filter(fn ($part) => $part !== null && $part !== '')->implode('-') . '.pdf';

        $merged = $tmpDir . '/package-' . uniqid() . '.pdf';

        $command = array_merge(
            ['gs', '-dBATCH', '-dNOPAUSE', '-q', '-sDEVICE=pdfwrite', '-sOutputFile=' . $merged],
            $parts,
        );
        $process = new \Symfony\Component\Process\Process($command);
        $process->setTimeout(120);
        $process->run();

        foreach ($cleanup as $path) {
            @unlink($path);
        }

        if (! $process->isSuccessful() || ! is_file($merged)) {
            // Fallback: hand back the first document rather than failing the click.
            abort_unless(is_file($parts[0]), 500, 'Could not build the combined PDF.');

            return response()->download($parts[0], $filename, ['Content-Type' => 'application/pdf']);
        }

        return response()->download($merged, $filename, ['Content-Type' => 'application/pdf'])
            ->deleteFileAfterSend(true);
    }

    /**
     * Stream a stored GCSS sworn statement PDF to the authorized user.
     */
    public function downloadSwornStatement(\App\Models\SwornStatement $swornStatement): BinaryFileResponse
    {
        abort_unless(
            (int) auth()->user()?->vendor?->id === (int) $swornStatement->belongs_to_vendor_id,
            403,
        );

        $absolute = $swornStatement->path
            ? \Storage::disk('files')->path($swornStatement->path)
            : null;

        abort_unless($absolute && is_file($absolute), 404);

        return response()->download(
            $absolute,
            $swornStatement->filename ?: basename($swornStatement->path),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Stream the latest PDF (signed if available, otherwise draft) for a
     * LienWaiver to the authorized user.
     */
    public function download(LienWaiver $lienWaiver): Response|BinaryFileResponse
    {
        $path = $lienWaiver->signed_path ?: $lienWaiver->draft_path;

        if ($path) {
            $absolute = \Storage::disk('files')->path($path);

            if (is_file($absolute)) {
                return response()->download(
                    $absolute,
                    // Always name the download with the current scheme, even
                    // when the stored file predates it.
                    LienWaiverDocumentGenerator::filenameFor($lienWaiver),
                    ['Content-Type' => 'application/pdf']
                );
            }
        }

        // No persisted file yet — render on the fly.
        try {
            $doc = LienWaiverDocumentGenerator::generate($lienWaiver);
        } catch (\Throwable) {
            throw new NotFoundHttpException('Unable to render lien waiver PDF.');
        }

        return response($doc['binary'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $doc['filename'] . '"',
        ]);
    }
}
