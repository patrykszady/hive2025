<?php

namespace App\Support\Permits;

use App\Models\Client;
use App\Models\Estimate;
use App\Models\PermitPacket;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Support\EstimateDocumentGenerator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds a project's permit packet (2026-10-26): the cover letter GS puts in
 * front of every plan set (resources/views/misc/permit-cover-letter), then
 * the Scope of Work & Contract (the estimate's own PDF, contract included),
 * the plans and the structural engineer's notes uploaded on the Permit
 * Packet card — merged into one PDF on the local disk. The village's codes
 * come from BuildingCodes for the town that issues the permit.
 */
class PermitPacketBuilder
{
    public const DISK = 'local';

    public function __construct(private HtmlToPdf $html, private PdfMerger $merger) {}

    /** "BASEMENT REMODEL" from a project called Basement; a name that already says what it is stays. */
    public static function defaultTitle(Project $project): string
    {
        $name = trim((string) $project->project_name);

        if ($name === '') {
            return 'REMODEL';
        }

        return Str::upper(preg_match('/remodel|addition|renovation|repair|build|install|replace/i', $name) ? $name : $name.' remodel');
    }

    /** @return array<string, mixed> everything the cover letter page prints */
    public function coverLetterData(PermitPacket $packet): array
    {
        $project = $packet->project ?? Project::withoutGlobalScopes()->findOrFail($packet->project_id);
        $client = $project->client_id ? Client::withoutGlobalScopes()->with(['users' => fn ($q) => $q->withoutGlobalScopes()])->find($project->client_id) : null;
        $vendor = Vendor::withoutGlobalScopes()->find($packet->belongs_to_vendor_id ?: $project->belongs_to_vendor_id);

        if (! $vendor) {
            throw new RuntimeException('The project has no company to write the cover letter for.');
        }

        return [
            'packet' => $packet,
            'project' => $project,
            'title' => trim((string) $packet->title) !== '' ? $packet->title : self::defaultTitle($project),
            'preparedFor' => array_values(array_filter([
                $client?->legal_name ?: $client?->name,
                trim((string) $project->address),
                trim(trim((string) $project->city).(trim((string) $project->city) !== '' && trim((string) $project->state) !== '' ? ', ' : '').trim((string) $project->state).' '.trim((string) $project->zip_code)),
            ])),
            'contents' => $packet->contents(),
            'codes' => array_values(array_filter(array_map('trim', (array) ($packet->codes ?: BuildingCodes::for($packet->jurisdiction))))),
            'vendorName' => (string) ($vendor->short_name ?: $vendor->business_name),
            'letterhead' => $this->letterhead($vendor),
        ];
    }

    /**
     * The letterhead, as the business card reads: the full badge logo over
     * the owners' names, the office email, the office phone, the website
     * (config/permits.php, else the vendor's own record).
     *
     * @return array{names: string, email: string, phone: string, website: string, logoDataUrl: ?string}
     */
    public function letterhead(Vendor $vendor): array
    {
        $host = preg_replace('#^https?://(www\.)?#', '', rtrim((string) $vendor->business_website, '/'));

        return [
            'names' => (string) (config('permits.letterhead.names.'.$vendor->id) ?: $this->ownersLegalNames($vendor)),
            'email' => trim((string) $vendor->business_email),
            'phone' => $vendor->business_phone ? phone_display((string) $vendor->business_phone) : '',
            'website' => $host ? 'www.'.$host : '',
            'logoDataUrl' => $this->letterheadLogoDataUrl($vendor),
        ];
    }

    /** The bundled full badge for the company, else the logo on its record. */
    protected function letterheadLogoDataUrl(Vendor $vendor): ?string
    {
        $bundled = config('permits.letterhead.logos.'.$vendor->id);

        if (is_string($bundled) && $bundled !== '' && is_file(public_path($bundled))) {
            return 'data:'.(mime_content_type(public_path($bundled)) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents(public_path($bundled)));
        }

        return EstimateDocumentGenerator::vendorLogoDataUrl($vendor);
    }

    /**
     * "Grzegorz & Patryk Szady": the company's owners (its call recipients,
     * else its admins) by legal first name, a shared last name said once.
     */
    protected function ownersLegalNames(Vendor $vendor): string
    {
        $people = collect($this->letterheadContacts($vendor));

        if ($people->isEmpty()) {
            return (string) ($vendor->short_name ?: $vendor->business_name);
        }

        return $people->groupBy('last_name')->map(function ($group, $lastName) {
            $firsts = $group->pluck('first_name')->filter()->values();
            $joined = $firsts->count() > 1 ? $firsts->slice(0, -1)->implode(', ').' & '.$firsts->last() : (string) $firsts->first();

            return trim($joined.' '.$lastName);
        })->implode(' & ');
    }

    public function coverLetterHtml(PermitPacket $packet): string
    {
        return view('misc.permit-cover-letter', $this->coverLetterData($packet))->render();
    }

    public function coverLetterPdf(PermitPacket $packet): string
    {
        return $this->html->pdf($this->coverLetterHtml($packet));
    }

    /**
     * Cover letter, then the estimate (Scope of Work & Contract), the plans
     * and the engineer's notes, merged and stored; the packet row remembers
     * where and when.
     */
    public function build(PermitPacket $packet, ?int $byUserId = null): PermitPacket
    {
        if (! $packet->plans_path || ! Storage::disk(self::DISK)->exists($packet->plans_path)) {
            throw new RuntimeException('Upload the plans before generating the packet.');
        }

        $workDir = storage_path('app/temp/permit-'.Str::uuid());
        mkdir($workDir, 0755, true);

        try {
            $parts = [];

            $cover = $workDir.'/1-cover-letter.pdf';
            file_put_contents($cover, $this->coverLetterPdf($packet));
            $parts[] = $cover;

            if ($packet->include_contract && $packet->estimate_id) {
                $estimate = Estimate::withoutGlobalScopes()->find($packet->estimate_id);
                if ($estimate) {
                    $contract = $workDir.'/2-scope-and-contract.pdf';
                    file_put_contents($contract, $this->estimatePdf($estimate));
                    $parts[] = $contract;
                }
            }

            $parts[] = Storage::disk(self::DISK)->path($packet->plans_path);

            if ($packet->notes_path && Storage::disk(self::DISK)->exists($packet->notes_path)) {
                $parts[] = Storage::disk(self::DISK)->path($packet->notes_path);
            }

            $merged = $workDir.'/packet.pdf';
            $this->merger->merge($parts, $merged);

            $project = $packet->project ?? Project::withoutGlobalScopes()->findOrFail($packet->project_id);
            // A fresh name every time, so a regenerate within the same second still replaces the old file.
            $relative = 'permit-packets/'.$project->id.'/permit-packet-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.pdf';
            Storage::disk(self::DISK)->put($relative, (string) file_get_contents($merged));

            $previous = $packet->packet_path;
            $packet->forceFill([
                'packet_path' => $relative,
                'packet_name' => self::packetFilename($project, $packet),
                'generated_at' => now(),
                'generated_by_user_id' => $byUserId,
            ])->save();

            if ($previous && $previous !== $relative) {
                Storage::disk(self::DISK)->delete($previous);
            }

            return $packet;
        } finally {
            foreach (glob($workDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($workDir);
        }
    }

    /** "Permit Packet - 123 Villa Circle Drive - Basement.pdf" */
    public static function packetFilename(Project $project, PermitPacket $packet): string
    {
        $parts = array_filter(['Permit Packet', trim((string) $project->address), trim((string) $project->project_name)]);

        return preg_replace('/[\\\\\/:*?"<>|]+/', '', implode(' - ', $parts)).'.pdf';
    }

    /** The estimate as the client signs it — scope, prices and the contract body. */
    protected function estimatePdf(Estimate $estimate): string
    {
        return (string) EstimateDocumentGenerator::generate($estimate, 'Estimate')['binary'];
    }

    /**
     * The letterhead's people: the company's call recipients (the owners the
     * phone rings), else its admins — legal names, for the owners' line.
     *
     * @return list<array{first_name: string, last_name: string}>
     */
    protected function letterheadContacts(Vendor $vendor): array
    {
        $ids = array_map('intval', (array) data_get($vendor->options, 'call_recipients', []));

        $people = $ids !== []
            ? User::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->get()
            : $vendor->users()->withoutGlobalScopes()->wherePivot('role_id', 1)->wherePivot('is_employed', 1)->orderBy('users.id')->limit(3)->get();

        return $people->map(fn (User $user) => [
            'first_name' => trim((string) $user->first_name),
            'last_name' => trim((string) $user->last_name),
        ])->filter(fn (array $person) => $person['first_name'] !== '')->sortBy('first_name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }
}
