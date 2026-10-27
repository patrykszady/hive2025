<?php

use App\Livewire\Estimates\EstimatePermitPacket;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\PermitPacket;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Jobs\Permits\FindVillageCodes;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VillageCodes;
use App\Services\GeoapifyService;
use App\Support\Permits\BuildingCodes;
use App\Support\Permits\HtmlToPdf;
use App\Support\Permits\PdfMerger;
use App\Support\Permits\PermitPacketBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * The permit packet (2026-10-26): GS's cover letter — title, who it is
 * prepared for, contents, the permit town's adopted codes — in front of the
 * signed scope of work and the plans, merged into one PDF from a small
 * dialog on the estimate page (the project card was "too much"). Built on 123 Villa Circle Dr (project 426): a Palatine mailing
 * address inside the City of Rolling Meadows.
 */

function permitWorld(): array
{
    config(['scout.driver' => null]);
    $gs = Vendor::query()->create(['business_name' => 'GS Construction & Remodeling, Inc', 'business_type' => 'GC', 'address' => '400 N Wheeling Rd', 'city' => 'Prospect Heights', 'state' => 'IL', 'zip_code' => '60070', 'business_phone' => '2247354200', 'business_email' => 'crew@gs.construction', 'business_website' => 'https://gs.construction']);
    $patryk = User::query()->create(['first_name' => 'Patryk', 'last_name' => 'Szady', 'email' => 'patryk.'.Str::random(5).'@gs.construction', 'cell_phone' => '2249993880', 'password' => bcrypt('x'), 'primary_vendor_id' => $gs->id, 'registration' => ['registered' => true]]);
    $greg = User::query()->create(['first_name' => 'Grzegorz', 'nickname' => 'Greg', 'last_name' => 'Szady', 'email' => 'greg.'.Str::random(5).'@gs.construction', 'cell_phone' => '8478737217', 'password' => bcrypt('x'), 'primary_vendor_id' => $gs->id, 'registration' => ['registered' => true]]);
    $gs->users()->attach([$patryk->id => ['role_id' => 1, 'is_employed' => 1], $greg->id => ['role_id' => 1, 'is_employed' => 1]]);
    $gs->forceFill(['options' => (object) ['short_name' => 'GS Construction', 'call_recipients' => [$patryk->id, $greg->id]]])->save();
    test()->actingAs($patryk);

    // A homeowner, no business name: the client's names come from its people (legal name on documents).
    $client = Client::factory()->create(['address' => '123 Villa Circle Drive', 'city' => 'Palatine', 'state' => 'IL', 'zip_code' => '60067']);
    $client->vendors()->attach($gs->id, ['source' => 'test']);
    $zach = User::query()->create(['first_name' => 'Zachary', 'nickname' => 'Zach', 'last_name' => 'Wong', 'email' => 'zach.'.Str::random(5).'@example.test', 'cell_phone' => '8478759229', 'password' => bcrypt('x'), 'registration' => ['registered' => true]]);
    $client->users()->attach($zach->id);

    $project = Project::query()->create(['project_name' => 'Basement', 'client_id' => $client->id, 'belongs_to_vendor_id' => $gs->id, 'address' => '123 Villa Circle Drive', 'city' => 'Palatine', 'state' => 'IL', 'zip_code' => '60067']);
    ProjectStatus::create(['project_id' => $project->id, 'belongs_to_vendor_id' => $gs->id, 'status_code' => 5, 'start_date' => now()->addDays(3)]);
    $estimate = Estimate::withoutGlobalScopes()->create(['project_id' => $project->id, 'belongs_to_vendor_id' => $gs->id, 'options' => []]);

    return compact('gs', 'patryk', 'greg', 'client', 'zach', 'project', 'estimate');
}

/** A one-page PDF saying $text — FPDF, no Chrome needed. */
function tinyPdf(string $text): string
{
    $pdf = new \FPDF;
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 14);
    $pdf->Cell(0, 10, $text);

    return $pdf->Output('S');
}

/** Stand in for Chrome: the cover letter's HTML becomes a one-page PDF carrying its title. */
function fakeHtmlToPdf(): void
{
    app()->instance(HtmlToPdf::class, new class extends HtmlToPdf
    {
        public array $rendered = [];

        public function pdf(string $html): string
        {
            $this->rendered[] = $html;
            preg_match('/data-cover-title>([^<]+)</', $html, $m);

            return tinyPdf('COVER '.($m[1] ?? '?'));
        }
    });
}

/** Pages in a PDF, by Ghostscript. */
function pdfPages(string $path): int
{
    $out = shell_exec('gs -q -dNODISPLAY -dNOSAFER -c "('.$path.') (r) file runpdfbegin pdfpagecount = quit" 2>/dev/null');

    return (int) trim((string) $out);
}

it('knows each town\'s adopted codes and falls back to the template\'s list', function () {
    expect(BuildingCodes::for('Rolling Meadows'))->toContain('International Residential Code, 2024 Edition', 'National Electrical Code, 2023 Edition', 'Illinois Energy Conservation Code, 2024 Edition')
        ->and(BuildingCodes::for('City of Rolling Meadows'))->toBe(BuildingCodes::for('rolling meadows'))
        ->and(BuildingCodes::for('Palatine'))->toContain('International Residential Code, 2021 Edition', 'National Electrical Code, 2020 Edition')
        ->and(BuildingCodes::for('Arlington Heights'))->toBe(BuildingCodes::DEFAULT_CODES)
        ->and(BuildingCodes::isKnown('Village of Palatine'))->toBeTrue()
        ->and(BuildingCodes::sourceFor('Rolling Meadows'))->toContain('cityrm.org');
});

it('suggests the town that issues the permit from the job\'s address, not its mailing city', function () {
    $w = permitWorld();

    $this->mock(GeoapifyService::class)->shouldReceive('geocodeAddress')->with('123 Villa Circle Drive, IL 60067')->andReturn(['city' => 'Rolling Meadows']);
    expect(BuildingCodes::townsFor($w['project']))->toBe(['Rolling Meadows', 'Palatine']);

    $this->mock(GeoapifyService::class)->shouldReceive('geocodeAddress')->andReturn(null);
    expect(BuildingCodes::townsFor($w['project']))->toBe(['Palatine']);
});

it('writes the cover letter the way the Word template reads: letterhead, title, prepared for, contents, codes, the detector note', function () {
    $w = permitWorld();
    $packet = new PermitPacket(['project_id' => $w['project']->id, 'belongs_to_vendor_id' => $w['gs']->id, 'estimate_id' => $w['estimate']->id, 'title' => 'BASEMENT REMODEL', 'jurisdiction' => 'Rolling Meadows', 'codes' => BuildingCodes::for('Rolling Meadows'), 'include_contract' => true, 'plans_path' => 'permit-packets/x/plans.pdf']);
    $packet->setRelation('project', $w['project']);

    $html = app(PermitPacketBuilder::class)->coverLetterHtml($packet);

    // The letterhead reads like the business card: the owners' names over the office email, phone and website.
    expect($html)->toContain('Grzegorz &amp; Patryk Szady')
        ->toContain('crew@gs.construction')->toContain('(224) 735-4200')->toContain('www.<b>gs</b>.construction')
        ->not->toContain('(224) 999-3880')
        ->toContain('data-cover-title>BASEMENT REMODEL<')
        ->toContain('ESPECIALLY PREPARED FOR')
        ->toContain('<p>Zachary Wong</p>')
        ->toContain('<p>123 Villa Circle Drive</p>')
        ->toContain('<p>Palatine, IL 60067</p>')
        ->toContain('<p>Cover Letter</p>')->toContain('<p>Scope of Work &amp; Contract</p>')->toContain('<p>Plans</p>')
        ->not->toContain('Structural Engineer Notes')
        ->toContain('International Residential Code, 2024 Edition')
        ->toContain('*All codes with applicable local amendments*')
        ->toContain('SMOKE DETECTOR SYSTEM:')
        ->toContain('To be installed per IRC 314.2');

    $packet->notes_path = 'permit-packets/x/notes.pdf';
    expect(app(PermitPacketBuilder::class)->coverLetterHtml($packet))->toContain('<p>Structural Engineer Notes</p>');

    // GS prints the owners as the card does, and its full badge, from config/permits.php.
    config(['permits.letterhead.names.'.$w['gs']->id => 'Gregory & Patryk Szady', 'permits.letterhead.logos.'.$w['gs']->id => 'images/letterhead/gs-construction.png']);
    $html = app(PermitPacketBuilder::class)->coverLetterHtml($packet);
    expect($html)->toContain('Gregory &amp; Patryk Szady')->toContain('data:image/png;base64,');
});

it('names the cover from the project: Basement becomes BASEMENT REMODEL, an addition stays what it is', function () {
    expect(PermitPacketBuilder::defaultTitle(new Project(['project_name' => 'Basement'])))->toBe('BASEMENT REMODEL')
        ->and(PermitPacketBuilder::defaultTitle(new Project(['project_name' => 'Kitchen'])))->toBe('KITCHEN REMODEL')
        ->and(PermitPacketBuilder::defaultTitle(new Project(['project_name' => 'Second Floor Addition'])))->toBe('SECOND FLOOR ADDITION');
});

it('asks Rolling Meadows or Palatine at 123 Villa Circle — the only two answers — and folds the codes away', function () {
    $w = permitWorld();
    $this->mock(GeoapifyService::class)->shouldReceive('geocodeAddress')->andReturn(['city' => 'Rolling Meadows']);

    // Mail says Palatine, the lot is in Rolling Meadows: a two-way picker, nothing else, with the note.
    Livewire::test(EstimatePermitPacket::class, ['estimate' => $w['estimate']])
        ->call('openModal')
        ->assertSet('title', 'BASEMENT REMODEL')
        ->assertSet('town', 'Rolling Meadows')
        ->assertSet('townOptions', ['Rolling Meadows', 'Palatine'])
        ->assertSet('pickingTown', true)
        ->assertSeeHtml('data-permit-town-picker')
        ->assertDontSee('Other town')
        ->assertSee('The lot sits in Rolling Meadows; the mail goes to Palatine.')
        ->assertSet('codesText', fn ($text) => str_contains($text, 'National Electrical Code, 2023 Edition'))
        ->assertSeeHtml('data-permit-codes-toggle')
        ->assertDontSee('cityrm.org')
        ->set('editingCodes', true)
        ->assertSee('cityrm.org')
        ->set('town', 'Palatine')
        ->assertSet('codesText', fn ($text) => str_contains($text, 'National Electrical Code, 2020 Edition'));
});

it('shows a plain town, never a picker, when the lot and the mail agree — known town or not', function () {
    $w = permitWorld();
    $w['project']->update(['city' => 'Rolling Meadows']);
    $this->mock(GeoapifyService::class)->shouldReceive('geocodeAddress')->andReturn(['city' => 'Rolling Meadows']);

    Livewire::test(EstimatePermitPacket::class, ['estimate' => $w['estimate']])
        ->call('openModal')
        ->assertSet('town', 'Rolling Meadows')
        ->assertSet('townOptions', ['Rolling Meadows'])
        ->assertSet('pickingTown', false)
        ->assertDontSeeHtml('data-permit-town-picker')
        ->assertDontSee('Permit issued by')
        ->assertSee('Rolling Meadows')
        ->assertSee('10 codes on the letter');

    // Lake Zurich has nobody's list yet: still just "Lake Zurich", the usual
    // codes for now, and a lookup of the village's website starts in the background.
    Queue::fake();
    $w['project']->update(['city' => 'Lake Zurich']);
    $this->mock(GeoapifyService::class)->shouldReceive('geocodeAddress')->andReturn(['city' => 'Lake Zurich']);
    $dialog = Livewire::test(EstimatePermitPacket::class, ['estimate' => $w['estimate']])
        ->call('openModal')
        ->assertSet('town', 'Lake Zurich')
        ->assertSet('pickingTown', false)
        ->assertDontSeeHtml('data-permit-town-picker')
        ->assertDontSee('Other town')
        ->assertSet('lookingUp', true)
        ->assertSeeHtml('data-permit-codes-lookup')
        ->assertSee('wire:poll.4s="checkLookup"', false)
        ->assertSet('codesText', implode("\n", BuildingCodes::DEFAULT_CODES));
    Queue::assertPushed(FindVillageCodes::class, fn (FindVillageCodes $job) => $job->town === 'Lake Zurich' && $job->state === 'IL');
    $row = VillageCodes::query()->named('Lake Zurich')->sole();
    expect($row->isPending())->toBeTrue();

    // Still looking: nothing changes. Then the website's list lands and replaces the usual one.
    $dialog->call('checkLookup')->assertSet('lookingUp', true);
    $row->update(['status' => VillageCodes::STATUS_FOUND, 'source_url' => 'https://lakezurich.org/202/Residential-Codes', 'codes' => ['International Building Code, 2018 Edition', 'National Electrical Code, 2017 Edition'], 'checked_at' => now()]);
    $dialog->call('checkLookup')
        ->assertSet('lookingUp', false)
        ->assertSet('codesText', "International Building Code, 2018 Edition\nNational Electrical Code, 2017 Edition")
        ->assertSee('2 codes on the letter')
        ->set('editingCodes', true)
        ->assertSee('From lakezurich.org, read '.now()->timezone('America/Chicago')->format('M j, Y').'.');

    // Opening again, the town is on file: no second lookup, no polling.
    Livewire::test(EstimatePermitPacket::class, ['estimate' => $w['estimate']])
        ->call('openModal')
        ->assertSet('lookingUp', false)
        ->assertSet('codesText', fn ($text) => str_starts_with($text, 'International Building Code, 2018 Edition'));
    Queue::assertPushed(FindVillageCodes::class, 1);

    // Lines someone typed are never overwritten by a late answer.
    $row->update(['status' => VillageCodes::STATUS_PENDING]);
    $w['project']->update(['city' => 'Long Grove']);
    $this->mock(GeoapifyService::class)->shouldReceive('geocodeAddress')->andReturn(['city' => 'Long Grove']);
    $typed = Livewire::test(EstimatePermitPacket::class, ['estimate' => $w['estimate']])
        ->call('openModal')
        ->assertSet('lookingUp', true)
        ->set('codesText', "My own line");
    VillageCodes::query()->named('Long Grove')->sole()->update(['status' => VillageCodes::STATUS_FOUND, 'codes' => ['International Residential Code, 2021 Edition'], 'source_url' => 'https://longgrove.org/codes']);
    $typed->call('checkLookup')->assertSet('lookingUp', false)->assertSet('codesText', 'My own line');

    // No geocoder answer at all: the mailing city stands alone.
    $this->mock(GeoapifyService::class)->shouldReceive('geocodeAddress')->andReturn(null);
    Livewire::test(EstimatePermitPacket::class, ['estimate' => $w['estimate']])
        ->call('openModal')
        ->assertSet('town', 'Long Grove')
        ->assertSet('pickingTown', false);
});

it('uploads the plans, builds the packet — cover, scope & contract, plans — and downloads it', function () {
    $w = permitWorld();
    Storage::fake('local');
    fakeHtmlToPdf();
    $this->mock(GeoapifyService::class)->shouldReceive('geocodeAddress')->andReturn(['city' => 'Rolling Meadows']);
    // A partial mock built with the real constructor arguments, so only the
    // estimate's PDF (Chrome) is stood in for; the merge runs for real.
    $builder = Mockery::mock(PermitPacketBuilder::class, [app(HtmlToPdf::class), app(PdfMerger::class)])->makePartial()->shouldAllowMockingProtectedMethods();
    $builder->shouldReceive('estimatePdf')->andReturn(tinyPdf('ESTIMATE 273 with contract'));
    app()->instance(PermitPacketBuilder::class, $builder);

    $plans = UploadedFile::fake()->createWithContent('123 villa 1.pdf', tinyPdf('LIGHTING PLAN'));

    $card = Livewire::test(EstimatePermitPacket::class, ['estimate' => $w['estimate']])
        ->call('openModal')
        ->set('plansUpload', $plans)
        ->assertHasNoErrors()
        ->assertSee('123 villa 1.pdf');

    $packet = PermitPacket::where('project_id', $w['project']->id)->sole();
    Storage::disk('local')->assertExists($packet->plans_path);

    // Generating hands the browser the file at once — no "ready" box to click through (Patryk, 2026-10-26).
    $card->call('generate')->assertHasNoErrors()->assertSet('error', null)->assertFileDownloaded('Permit Packet - 123 Villa Circle Drive - Basement.pdf');

    $packet->refresh();
    expect($packet->packet_path)->toStartWith('permit-packets/'.$w['project']->id.'/permit-packet-')
        ->and($packet->packet_name)->toBe('Permit Packet - 123 Villa Circle Drive - Basement.pdf')
        ->and($packet->generated_by_user_id)->toBe($w['patryk']->id)
        ->and($packet->jurisdiction)->toBe('Rolling Meadows')
        ->and($packet->codes)->toContain('National Electrical Code, 2023 Edition')
        ->and(pdfPages(Storage::disk('local')->path($packet->packet_path)))->toBe(3, 'cover + scope & contract + plans');

    // The last packet can be fetched again without rebuilding it.
    $card->assertSeeHtml('data-permit-packet-last')->call('download')->assertFileDownloaded('Permit Packet - 123 Villa Circle Drive - Basement.pdf');

    // Notes make a fourth part; regenerating replaces the file.
    $first = $packet->packet_path;
    $card->set('notesUpload', UploadedFile::fake()->createWithContent('engineer.pdf', tinyPdf('ENGINEER NOTES')))->assertHasNoErrors()
        ->call('generate')->assertHasNoErrors();
    $packet->refresh();
    expect($packet->packet_path)->not->toBe($first)
        ->and(pdfPages(Storage::disk('local')->path($packet->packet_path)))->toBe(4);
    Storage::disk('local')->assertMissing($first);
})->skip(fn () => trim((string) shell_exec('which gs')) === '', 'Ghostscript is not installed here');

it('will not generate without plans, and keeps the words saved for the next person', function () {
    $w = permitWorld();
    Storage::fake('local');
    $this->mock(GeoapifyService::class)->shouldReceive('geocodeAddress')->andReturn(null);

    Livewire::test(EstimatePermitPacket::class, ['estimate' => $w['estimate']])
        ->call('openModal')
        ->set('title', 'BASEMENT FINISH')
        ->set('codesText', "International Residential Code, 2024 Edition\n\nNational Electrical Code, 2023 Edition")
        ->call('generate')
        ->assertSet('error', 'Upload the plans first.');

    $packet = PermitPacket::where('project_id', $w['project']->id)->sole();
    expect($packet->title)->toBe('BASEMENT FINISH')
        ->and($packet->codes)->toBe(['International Residential Code, 2024 Edition', 'National Electrical Code, 2023 Edition'])
        ->and($packet->packet_path)->toBeNull();

    Livewire::test(EstimatePermitPacket::class, ['estimate' => $w['estimate']])->call('openModal')->assertSet('title', 'BASEMENT FINISH');
});

it('is for the team that can edit the project', function () {
    $w = permitWorld();
    $outsider = User::query()->create(['first_name' => 'Out', 'last_name' => 'Sider', 'email' => 'o.'.Str::random(5).'@example.test', 'cell_phone' => '2245550199', 'password' => bcrypt('x'), 'registration' => ['registered' => true]]);

    Livewire::actingAs($outsider)->test(EstimatePermitPacket::class, ['estimate' => $w['estimate']])->call('generate')->assertForbidden();
});
