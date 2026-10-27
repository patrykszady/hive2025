<?php

namespace App\Livewire\Estimates;

use App\Models\Estimate;
use App\Models\PermitPacket;
use App\Models\Project;
use App\Models\VillageCodes;
use App\Support\Permits\BuildingCodes;
use App\Support\Permits\PermitPacketBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Permit Packet" on the estimate page (2026-10-26): one small dialog that
 * puts GS's cover letter in front of this estimate (the Scope of Work &
 * Contract) and the plans, and hands the browser the merged PDF
 * (PermitPacketBuilder). The permit town is suggested from the job's
 * address — not its mailing city — and brings the codes its building
 * department has adopted (BuildingCodes); the codes stay editable behind a
 * link. One packet per project, rebuilt in place.
 */
class EstimatePermitPacket extends Component
{
    use AuthorizesRequests, WithFileUploads;

    public Estimate $estimate;

    public string $title = '';

    /** The town whose codes go on the letter; the town itself is not printed. */
    public string $town = '';

    /**
     * The towns this job could belong to (BuildingCodes::townsFor). Two of
     * them — the lot geocodes to one town, the mail says another, as at 123
     * Villa Circle (Rolling Meadows / Palatine) — and the dialog shows a
     * two-way picker. One, and the town is a word of text (Patryk,
     * 2026-10-26: "never show 'other town' … Palatine and Rolling Meadows are
     * the only 2 true answers").
     *
     * @var list<string>
     */
    public array $townOptions = [];

    /** The CODES block, one per line; shown only when opened for editing. */
    public string $codesText = '';

    public bool $editingCodes = false;

    public bool $pickingTown = false;

    /**
     * True while the town's codes are being read off its website in the
     * background (BuildingCodes::lookup → FindVillageCodes); the dialog polls
     * checkLookup() and swaps the template list for the real one when it
     * lands — unless someone has already edited the lines.
     */
    public bool $lookingUp = false;

    public bool $codesEdited = false;

    public bool $withNotes = false;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $plansUpload = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $notesUpload = null;

    public ?string $error = null;

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate;
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'title' => 'required|string|max:120',
            'town' => 'nullable|string|max:120',
            'codesText' => 'nullable|string|max:4000',
            'plansUpload' => 'nullable|file|mimes:pdf|max:61440',
            'notesUpload' => 'nullable|file|mimes:pdf|max:61440',
        ];
    }

    #[On('openPermitPacket')]
    public function openModal(): void
    {
        $this->authorize('update', $this->estimate);
        $this->reset(['plansUpload', 'notesUpload', 'error', 'editingCodes']);
        unset($this->packet);

        $packet = $this->packet;
        $project = $this->project();
        $this->townOptions = BuildingCodes::townsFor($project);
        $this->pickingTown = count($this->townOptions) > 1;

        if ($packet) {
            $this->title = (string) $packet->title;
            $this->town = $this->chooseTown((string) $packet->jurisdiction);
            $this->withNotes = (bool) $packet->notes_path;
            $this->loadCodesFor($this->town);
            if (! empty($packet->codes) && array_values($packet->codes) !== BuildingCodes::DEFAULT_CODES) {
                $this->codesText = implode("\n", $packet->codes);
                $this->codesEdited = true;
            }
        } else {
            $this->title = PermitPacketBuilder::defaultTitle($project);
            $this->town = $this->chooseTown('');
            $this->withNotes = false;
            $this->loadCodesFor($this->town);
        }

        $this->modal('estimate-permit-packet-modal')->show();
    }

    /** Switching between the two towns swaps the codes. */
    public function updatedTown(): void
    {
        $this->loadCodesFor($this->town);
    }

    public function updatedCodesText(): void
    {
        $this->codesEdited = true;
    }

    /** The town's list — and, for a town nobody has looked up, a search in the background. */
    protected function loadCodesFor(string $town): void
    {
        $this->codesText = implode("\n", BuildingCodes::for($town));
        $this->codesEdited = false;
        $this->lookingUp = false;

        if ($town !== '' && ! BuildingCodes::isKnown($town)) {
            $row = BuildingCodes::lookup($town);
            $this->lookingUp = (bool) $row?->isPending();
        }
    }

    /** Polled while a town is being looked up. */
    public function checkLookup(): void
    {
        if (! $this->lookingUp) {
            return;
        }

        $row = BuildingCodes::row($this->town);
        if (! $row || $row->isPending()) {
            return;
        }

        $this->lookingUp = false;
        if ($row->isFound() && ! $this->codesEdited) {
            $this->codesText = implode("\n", $row->codes);
        }
    }

    /** What the Codes box should say about where its lines came from. */
    public function codesOrigin(): string
    {
        $town = $this->jurisdiction();
        $row = BuildingCodes::row($town);
        $source = $this->codesSource();
        $host = $source ? preg_replace('/^www\./', '', (string) parse_url($source, PHP_URL_HOST)) : null;

        if ($row?->isFound() && $row->found_by === VillageCodes::FOUND_BY_AI) {
            return sprintf('From %s, read %s. With local amendments.', $host, $row->checked_at?->timezone('America/Chicago')->format('M j, Y'));
        }
        if ($source) {
            return sprintf('From %s, with local amendments.', $host);
        }
        if ($this->lookingUp) {
            return sprintf('Looking up %s\'s codes on the village\'s website…', $town);
        }

        return sprintf('No list found on %s\'s website — the usual list; check it against the village\'s.', $town ?: 'this town');
    }

    public function updatedPlansUpload(): void
    {
        $this->authorize('update', $this->estimate);
        $this->validateOnly('plansUpload');
        $this->storeUpload('plans');
    }

    public function updatedNotesUpload(): void
    {
        $this->authorize('update', $this->estimate);
        $this->validateOnly('notesUpload');
        $this->storeUpload('notes');
    }

    public function removeNotes(): void
    {
        $this->authorize('update', $this->estimate);

        $packet = $this->packet;
        if ($packet?->notes_path) {
            Storage::disk(PermitPacketBuilder::DISK)->delete($packet->notes_path);
            $packet->update(['notes_path' => null, 'notes_name' => null]);
        }
        $this->withNotes = false;
        unset($this->packet);
    }

    /** The cover letter alone, as it will print. */
    public function previewCoverLetter(PermitPacketBuilder $builder): ?StreamedResponse
    {
        $this->authorize('update', $this->estimate);
        $this->validate();
        $packet = $this->persist();

        try {
            $pdf = $builder->coverLetterPdf($packet);
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'The cover letter could not be rendered: '.Str::limit($e->getMessage(), 200);

            return null;
        }

        return response()->streamDownload(fn () => print($pdf), 'Cover Letter - '.trim((string) $this->project()->address).'.pdf', ['Content-Type' => 'application/pdf']);
    }

    /** Cover letter + this estimate + plans (+ notes) → one PDF, downloaded as soon as it is built. */
    public function generate(PermitPacketBuilder $builder): ?StreamedResponse
    {
        $this->authorize('update', $this->estimate);
        $this->validate();
        $packet = $this->persist();
        $this->error = null;

        if (! $packet->plans_path) {
            $this->error = 'Upload the plans first.';

            return null;
        }

        try {
            $builder->build($packet, auth()->id());
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'The packet could not be generated: '.Str::limit($e->getMessage(), 200);

            return null;
        }

        unset($this->packet);

        return $this->download();
    }

    public function download(): ?StreamedResponse
    {
        $this->authorize('view', $this->estimate);

        $packet = $this->packet;

        if (! $packet?->packet_path || ! Storage::disk(PermitPacketBuilder::DISK)->exists($packet->packet_path)) {
            $this->error = 'Generate the packet first.';

            return null;
        }

        $path = Storage::disk(PermitPacketBuilder::DISK)->path($packet->packet_path);

        return response()->streamDownload(fn () => readfile($path), $packet->packet_name ?: 'Permit Packet.pdf', ['Content-Type' => 'application/pdf']);
    }

    #[Computed]
    public function packet(): ?PermitPacket
    {
        return $this->estimate->project_id
            ? PermitPacket::query()->where('project_id', $this->estimate->project_id)->latest('id')->first()
            : null;
    }

    /** @return list<string> */
    #[Computed]
    public function towns(): array
    {
        return BuildingCodes::jurisdictions();
    }

    /** The town whose codes are on the letter. */
    public function jurisdiction(): string
    {
        return trim($this->town);
    }

    public function codesSource(): ?string
    {
        return BuildingCodes::sourceFor($this->jurisdiction());
    }

    /** The saved town when it is still one of the candidates, else the first candidate (the lot's own town). */
    protected function chooseTown(string $saved): string
    {
        foreach ($this->townOptions as $option) {
            if ($saved !== '' && mb_strtolower($option) === mb_strtolower($saved)) {
                return $option;
            }
        }

        return $this->townOptions[0] ?? $saved;
    }

    protected function project(): Project
    {
        return Project::withoutGlobalScopes()->findOrFail($this->estimate->project_id);
    }

    protected function persist(): PermitPacket
    {
        $project = $this->project();
        $codes = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $this->codesText) ?: [])));

        $packet = PermitPacket::query()->firstOrNew(['project_id' => $project->id]);
        $packet->fill([
            'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
            'estimate_id' => $this->estimate->id,
            'title' => trim($this->title),
            'jurisdiction' => $this->jurisdiction() ?: null,
            'codes' => $codes,
            'include_contract' => true,
        ]);
        if (! $packet->exists) {
            $packet->created_by_user_id = auth()->id();
        }
        $packet->save();
        unset($this->packet);

        return $packet;
    }

    /** @param  'plans'|'notes'  $kind */
    protected function storeUpload(string $kind): void
    {
        $upload = $kind === 'plans' ? $this->plansUpload : $this->notesUpload;
        if (! $upload) {
            return;
        }

        $packet = $this->persist();
        $path = $upload->storeAs('permit-packets/'.$packet->project_id, $kind.'-'.Str::uuid().'.pdf', PermitPacketBuilder::DISK);

        if ($packet->{$kind.'_path'}) {
            Storage::disk(PermitPacketBuilder::DISK)->delete($packet->{$kind.'_path'});
        }

        $packet->update([$kind.'_path' => $path, $kind.'_name' => mb_substr($upload->getClientOriginalName(), 0, 200)]);
        $this->{$kind.'Upload'} = null;
        $this->error = null;
        unset($this->packet);
    }

    public function render(): View
    {
        return view('livewire.estimates.permit-packet');
    }
}
