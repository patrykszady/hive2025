<?php

use App\Enums\LienWaiverStatus;
use App\Enums\LienWaiverType;
use App\Livewire\LienWaivers\Index;
use App\Models\Bid;
use App\Models\Check;
use App\Models\Client;
use App\Models\Expense;
use App\Models\LienWaiver;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Support\SwornStatementGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function swornStatementFixtures(): array
{
    // Sub waivers are emailed on creation; keep the queue faked so tests
    // don't render PDFs inline.
    Illuminate\Support\Facades\Bus::fake([\App\Jobs\SendLienWaiverSigningRequestJob::class]);

    $gc = Vendor::query()->create([
        'business_name' => 'GS Construction & Remodeling, Inc',
        'business_type' => 'Sub',
        'business_email' => 'gc@example.test',
        'address' => '400 N Wheeling Rd', 'city' => 'Prospect Heights', 'state' => 'IL', 'zip_code' => '60070',
    ]);
    $sub = Vendor::query()->create([
        'business_name' => 'PMG Carpentry Inc',
        'business_type' => 'Sub',
        'business_email' => 'pmg@example.test',
        'address' => '1008 E Northwest Hwy', 'city' => 'Mount Prospect', 'state' => 'IL', 'zip_code' => '60056',
    ]);
    $retail = Vendor::query()->create([
        'business_name' => 'Fedex',
        'business_type' => 'Retail',
        'business_email' => 'fedex@example.test',
        'address' => '1 Ship St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);

    $user = User::query()->create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'sworn-' . Str::random(8) . '@example.test',
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
    ]);
    $user->forceFill(['primary_vendor_id' => $gc->id])->saveQuietly();
    $user->vendors()->attach($gc->id, ['role_id' => 1, 'is_employed' => true, 'position' => 'Secretary']);

    // PMG's default work type prefills its Kind of Work on the statement
    // (kinds are required for every included vendor).
    $carpentry = \App\Models\WorkType::create(['belongs_to_vendor_id' => $gc->id, 'name' => 'Carpentry']);
    $sub->forceFill(['work_type_id' => $carpentry->id])->save();

    // ProjectObserver::creating derives belongs_to_vendor_id from the auth user.
    test()->actingAs($user->fresh());

    $project = Project::query()->create([
        'project_name' => 'Home Renovation',
        'belongs_to_vendor_id' => $gc->id,
        'client_id' => Client::query()->create([
            'business_name' => 'Mark & Gail Brodson',
            'address' => '3154 Violet Ln', 'city' => 'Northbrook', 'state' => 'IL', 'zip_code' => '60062',
        ])->id,
        'address' => '3154 Violet Ln', 'city' => 'Northbrook', 'state' => 'IL', 'zip_code' => '60062',
    ]);

    // GC contract: original bid + change order.
    Bid::withoutGlobalScopes()->create(['project_id' => $project->id, 'vendor_id' => $gc->id, 'amount' => 559258.50, 'type' => 1]);
    Bid::withoutGlobalScopes()->create(['project_id' => $project->id, 'vendor_id' => $gc->id, 'amount' => 950.00, 'type' => 2]);

    // Owner has paid two 60k draws.
    foreach (['2026-06-01', '2026-07-09'] as $date) {
        Payment::withoutGlobalScopes()->create([
            'project_id' => $project->id, 'belongs_to_vendor_id' => $gc->id,
            'amount' => 60000, 'date' => $date, 'created_by_user_id' => $user->id,
        ]);
    }

    // PMG: paid 30k by check, no bid recorded (contract unknown).
    $check = Check::create([
        'check_type' => 'Check', 'check_number' => 2632, 'date' => '2026-07-09', 'amount' => 30000,
        'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $gc->id, 'created_by_user_id' => $user->id,
    ]);
    Expense::withoutGlobalScopes()->create([
        'project_id' => $project->id, 'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $gc->id,
        'check_id' => $check->id, 'amount' => 30000, 'date' => '2026-07-09', 'created_by_user_id' => $user->id,
    ]);

    // FedEx: incidental retail expense — discovered but excluded by default.
    Expense::withoutGlobalScopes()->create([
        'project_id' => $project->id, 'vendor_id' => $retail->id, 'belongs_to_vendor_id' => $gc->id,
        'amount' => 102.16, 'date' => '2026-06-15', 'created_by_user_id' => $user->id,
    ]);

    return [$gc, $sub, $retail, $user, $project];
}

it('prefills sworn statement rows from project money, excluding the GC and defaulting retail off', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    $rows = SwornStatementGenerator::buildRows($project, $gc);

    expect($rows)->toHaveCount(2);

    $byId = collect($rows)->keyBy('vendor_id');

    // The Vendor model title-cases business_name on save, so match by id.
    expect($byId->keys()->all())->not->toContain($gc->id)
        ->and($byId[$sub->id]['name'])->toBe($sub->fresh()->business_name)
        ->and($byId[$sub->id]['include'])->toBeTrue()
        ->and($byId[$sub->id]['paid'])->toBe(30000.0)
        ->and($byId[$sub->id]['contract'])->toBe('') // no bid on file
        ->and($byId[$retail->id]['include'])->toBeFalse()
        ->and($byId[$retail->id]['paid'])->toBe(102.16);
});

it('generates a sworn statement whose columns foot to the contract totals', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    $rows = SwornStatementGenerator::buildRows($project, $gc);

    // User fills PMG's contract and pays them 10k out of this draw.
    $rows = collect($rows)->map(function ($row) use ($sub) {
        if ($row['vendor_id'] === $sub->id) {
            $row['kind'] = 'Carpentry';
            $row['contract'] = '45,000.00';
            $row['this_payment'] = '10,000.00';
        }

        return $row;
    })->all();

    // Assert on the rendered HTML (generate() returns an opaque PDF binary
    // when Chrome is available; the download path is covered by the modal test).
    $context = SwornStatementGenerator::buildContext($project, $gc, $rows, 60000.0);
    $html = view('pdf.sworn-statement', $context['view'])->render();

    expect($context['filename'])->toBe('sworn-statement-gs-construction-remodeling-inc-mark-gail-brodson-3154-violet-ln-' . now()->format('Y-m-d') . '.pdf')
        ->and($html)
        ->toContain('Sworn Statement of Contractor')
        ->toContain('Mark &amp; Gail Brodson')
        ->toContain(e($sub->fresh()->business_name))
        ->toContain('Carpentry')
        ->toContain('45,000.00')      // PMG contract
        ->toContain('10,000.00')      // PMG this payment
        ->toContain('5,000.00')       // PMG balance 45k − 30k − 10k
        ->toContain('559,258.50')     // original contract
        ->toContain('950.00')         // extras
        ->toContain('560,208.50')     // contract total
        ->toContain('120,000.00')     // owner previously paid
        ->toContain('60,000.00')      // this draw
        ->toContain('380,208.50')     // balance to become due
        ->toContain('515,208.50')     // GC line contract: 560,208.50 − 45,000
        ->toContain('Notary Public')
        ->not->toContain($retail->fresh()->business_name); // excluded by default

    // GC balancing line: paid 120k − 30k = 90k; this 60k − 10k = 50k.
    expect($html)->toContain('90,000.00')->toContain('50,000.00');
});

it('opens the modal, edits rows, and streams the PDF download', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    Illuminate\Support\Facades\Mail::fake();

    Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openSwornStatement')
        ->assertSet('showSwornStatement', true)
        ->assertSee('Sworn Statement + Sub Waivers')
        ->assertSee($sub->fresh()->business_name)
        ->set('ssRows.0.kind', 'Carpentry')
        ->set('ssThisPayment', '60,000')
        ->call('generateSwornStatement')
        ->assertHasNoErrors()
        ->assertSet('showSwornStatement', false)
        ->assertFileDownloaded('gc-draw-package-gs-construction-remodeling-inc-mark-gail-brodson-3154-violet-ln-draw-1-' . now()->format('Y-m-d') . '.pdf');

    // The producer gets the GCSS + GC waiver package by email, and the send
    // flips both documents to Sent.
    Illuminate\Support\Facades\Mail::assertSent(
        \App\Mail\DrawPackageMail::class,
        fn ($mail) => $mail->hasTo($user->email) && $mail->drawNumber === 1,
    );

    $statement = \App\Models\SwornStatement::query()->latest('id')->first();
    expect($statement->status)->toBe(LienWaiverStatus::Sent)
        ->and(LienWaiver::withoutGlobalScopes()
            ->where('sworn_statement_id', $statement->id)
            ->where('vendor_id', $gc->id)
            ->value('status'))->toBe(LienWaiverStatus::Sent);
});

it('one step: generating the statement also creates a sub waiver+affidavit for each listed sub', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    // A second sub already carrying an OPEN draft waiver — must be skipped.
    $cardona = Vendor::query()->create([
        'business_name' => 'Arturo Cardona',
        'business_type' => '1099',
        'business_email' => 'cardona@example.test',
        'address' => '941 W Windsor', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60640',
    ]);
    $cardona->forceFill(['work_type_id' => \App\Models\WorkType::resolve($gc->id, 'Carpentry')->id])->save();
    Expense::withoutGlobalScopes()->create([
        'project_id' => $project->id, 'vendor_id' => $cardona->id, 'belongs_to_vendor_id' => $gc->id,
        'amount' => 6000, 'date' => '2026-06-20', 'created_by_user_id' => $user->id,
    ]);
    $existing = LienWaiver::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $gc->id, 'vendor_id' => $cardona->id, 'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalProgress, 'status' => LienWaiverStatus::Draft,
        'amount' => 6000, 'exceptions_amount' => 0, 'through_date' => now()->toDateString(),
        'jurisdiction' => 'US-IL', 'created_by_user_id' => $user->id,
    ]);

    expect(LienWaiver::withoutGlobalScopes()->where('project_id', $project->id)->count())->toBe(1);

    $component = Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openSwornStatement');

    // Type PMG's kind of work like on the GCSS, and check the retail vendor
    // onto the statement (it must still not get a waiver).
    $rows = collect($component->get('ssRows'));
    $pmgIndex = $rows->search(fn ($r) => $r['vendor_id'] === $sub->id);
    $retailIndex = $rows->search(fn ($r) => $r['vendor_id'] === $retail->id);

    $component->set("ssRows.{$pmgIndex}.kind", 'Carpentry')
        ->set("ssRows.{$retailIndex}.include", true)
        ->set("ssRows.{$retailIndex}.kind", 'Shipping')
        ->set('ssThisPayment', '60,000')
        ->call('generateSwornStatement')
        ->assertHasNoErrors()
        ->assertFileDownloaded('gc-draw-package-gs-construction-remodeling-inc-mark-gail-brodson-3154-violet-ln-draw-1-' . now()->format('Y-m-d') . '.pdf');

    // PMG gets a fresh sub waiver; Cardona is skipped (already had an open
    // one); the GC gets its own waiver for the draw; and the retail vendor
    // gets one too — every party listed on the statement swears a waiver, and
    // retail vendors without an email are mailed to the draw's creator to
    // forward (they used to be silently skipped, leaving haulers and rental
    // companies on the GCSS with nothing to chase).
    $waivers = LienWaiver::withoutGlobalScopes()->where('project_id', $project->id)->get();

    expect($waivers)->toHaveCount(4) // Cardona + PMG + GC draw waiver + retail
        ->and($waivers->firstWhere('vendor_id', $retail->id))->not->toBeNull();

    $pmg = $waivers->firstWhere('vendor_id', $sub->id);
    expect($pmg)->not->toBeNull()
        ->and((float) $pmg->amount)->toBe(30000.0)   // money received to date
        ->and($pmg->belongs_to_vendor_id)->toBe($gc->id)
        ->and($pmg->isSubWaiver())->toBeTrue()
        // The GCSS row's Kind of Work rides along into the waiver…
        ->and(json_decode($pmg->notes, true)['kind_of_work'])->toBe('Carpentry');

    // …and the waiver's affidavit prints the same "What For" as the statement.
    $pmgHtml = view('pdf.lien-waiver', [
        'waiver' => $pmg,
        'project' => $project,
        'vendor' => $sub->fresh(),
        'payerVendor' => Vendor::withoutGlobalScopes()->find($gc->id),
        'payerOverride' => null,
        'check' => null,
        'payment' => null,
        'signatures' => collect(),
        'isSigned' => false,
        'isDraft' => true,
        'isSubWaiver' => true,
        'projectCounty' => 'Cook',
        'amountWords' => 'Thirty Thousand',
        'affidavit' => ['original_contract' => 45000.0, 'extras' => 0.0, 'contract_total' => 45000.0, 'amount_paid' => 30000.0, 'this_payment' => 0.0, 'balance_due' => 15000.0],
    ])->render();

    expect($pmgHtml)
        ->toContain('Carpentry — labor &amp; material (incl. extras)')  // What For
        ->toContain('FURNISHING <span class="fill">carpentry</span>')   // affiant line trade
        ->toContain('30,000.00');                                       // real amount, never hidden

    $gcWaiver = $waivers->firstWhere('vendor_id', $gc->id);
    expect($gcWaiver)->not->toBeNull()
        ->and((float) $gcWaiver->amount)->toBe(60000.0)  // the draw amount
        ->and($gcWaiver->isSubWaiver())->toBeFalse()
        ->and(json_decode($gcWaiver->notes, true)['payer']['name'])->toBe('Mark & Gail Brodson');

    expect($waivers->where('vendor_id', $cardona->id)->count())->toBe(1)  // not duplicated
        // Retail vendors DO get a waiver now — one each, like everyone else on
        // the statement.
        ->and($waivers->where('vendor_id', $retail->id)->count())->toBe(1);
});

it('creates a fresh sub waiver when the previous one was deleted', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    // PMG had a draft that the user deleted — it must NOT block a new one.
    $old = LienWaiver::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $gc->id, 'vendor_id' => $sub->id, 'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalProgress, 'status' => LienWaiverStatus::Draft,
        'amount' => 30000, 'exceptions_amount' => 0, 'through_date' => now()->toDateString(),
        'jurisdiction' => 'US-IL', 'created_by_user_id' => $user->id,
    ]);
    $old->delete(); // soft-delete, as the UI's delete action does

    Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openSwornStatement')
        ->set('ssThisPayment', '60,000')
        ->call('generateSwornStatement')
        ->assertHasNoErrors();

    $active = LienWaiver::withoutGlobalScopes()
        ->where('project_id', $project->id)
        ->where('vendor_id', $sub->id)
        ->whereNull('deleted_at')
        ->get();

    expect($active)->toHaveCount(1)
        ->and((float) $active->first()->amount)->toBe(30000.0);
});

it('does not duplicate sub waivers when the statement is generated twice', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    $component = Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openSwornStatement')
        ->set('ssThisPayment', '60,000')
        ->call('generateSwornStatement')
        ->assertHasNoErrors();

    expect(LienWaiver::withoutGlobalScopes()->where('project_id', $project->id)->where('vendor_id', $sub->id)->count())->toBe(1)
        ->and(LienWaiver::withoutGlobalScopes()->where('project_id', $project->id)->where('vendor_id', $gc->id)->count())->toBe(1);

    // Second run: PMG and the GC already have open drafts → no new waivers.
    $component->call('openSwornStatement')
        ->set('ssThisPayment', '60,000')
        ->call('generateSwornStatement')
        ->assertHasNoErrors();

    expect(LienWaiver::withoutGlobalScopes()->where('project_id', $project->id)->where('vendor_id', $sub->id)->count())->toBe(1)
        ->and(LienWaiver::withoutGlobalScopes()->where('project_id', $project->id)->where('vendor_id', $gc->id)->count())->toBe(1);
});

it('saves typed contract amounts as bids without touching existing ones, then prefills next time', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    // A second sub with an existing bid the user will edit in the modal.
    $cardona = Vendor::query()->create([
        'business_name' => 'Arturo Cardona',
        'business_type' => '1099',
        'business_email' => 'cardona@example.test',
        'address' => '941 W Windsor', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60640',
    ]);
    Bid::withoutGlobalScopes()->create(['project_id' => $project->id, 'vendor_id' => $cardona->id, 'amount' => 9300, 'type' => 1]);
    $cardona->forceFill(['work_type_id' => \App\Models\WorkType::resolve($gc->id, 'Carpentry')->id])->save();
    $cardona->forceFill(['work_type_id' => \App\Models\WorkType::resolve($gc->id, 'Carpentry')->id])->save();
    Expense::withoutGlobalScopes()->create([
        'project_id' => $project->id, 'vendor_id' => $cardona->id, 'belongs_to_vendor_id' => $gc->id,
        'amount' => 6000, 'date' => '2026-06-20', 'created_by_user_id' => $user->id,
    ]);

    $component = Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openSwornStatement');

    $rows = collect($component->get('ssRows'));
    $pmgIndex = $rows->search(fn ($r) => $r['vendor_id'] === $sub->id);
    $cardonaIndex = $rows->search(fn ($r) => $r['vendor_id'] === $cardona->id);

    expect($rows[$cardonaIndex]['contract'])->toBe('9,300.00'); // prefilled from bid

    $component
        ->set("ssRows.{$pmgIndex}.contract", '45,000.00')      // no bid → should save
        ->set("ssRows.{$cardonaIndex}.contract", '12,000.00')  // has bid → must NOT overwrite
        ->set('ssThisPayment', '60,000')
        ->call('generateSwornStatement')
        ->assertHasNoErrors();

    $pmgBids = Bid::withoutGlobalScopes()->where('project_id', $project->id)->where('vendor_id', $sub->id)->get();
    expect($pmgBids)->toHaveCount(1)
        ->and((float) $pmgBids->first()->amount)->toBe(45000.0)
        ->and($pmgBids->first()->type)->toBe(1);

    $cardonaBids = Bid::withoutGlobalScopes()->where('project_id', $project->id)->where('vendor_id', $cardona->id)->get();
    expect($cardonaBids)->toHaveCount(1)
        ->and((float) $cardonaBids->first()->amount)->toBe(9300.0); // untouched

    // Re-opening prefills PMG's contract from the freshly saved bid.
    $component->call('openSwornStatement');
    $freshRows = collect($component->get('ssRows'));
    $pmgRow = $freshRows->firstWhere('vendor_id', $sub->id);
    expect($pmgRow['contract'])->toBe('45,000.00');
});

it('splits change orders into extras and credits, appends the kind-of-work suffix', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    // A deduction change order: -2,000 credit to the contract.
    Bid::withoutGlobalScopes()->create(['project_id' => $project->id, 'vendor_id' => $gc->id, 'amount' => -2000, 'type' => 2]);

    $rows = collect(SwornStatementGenerator::buildRows($project, $gc))->map(function ($row) {
        if (str_contains($row['name'], 'Pmg')) {
            $row['kind'] = 'Carpentry';
        }

        if (str_contains($row['name'], 'Fedex')) {
            $row['include'] = true;
            $row['kind'] = 'Shipping'; // retail → kind as typed, no suffix
        }

        return $row;
    })->all();

    $context = SwornStatementGenerator::buildContext($project, $gc, $rows, 60000.0);
    $summary = $context['view']['summary'];

    expect($summary['extras'])->toBe(950.0)               // positive COs only
        ->and($summary['credits'])->toBe(2000.0)          // deductions, shown positive
        ->and($summary['contract_total'])->toBe(560208.50) // original + extras (pre-credit)
        ->and($summary['adjusted_total'])->toBe(558208.50) // − credits
        ->and($summary['balance_due'])->toBe(378208.50);   // adjusted − 120k − 60k

    $html = view('pdf.sworn-statement', $context['view'])->render();

    expect($html)
        ->toContain('558,208.50')
        ->toContain('2,000.00')
        ->toContain('Carpentry — labor &amp; material (incl. extras)')       // trade sub: typed kind + suffix
        ->toContain('Shipping')                                              // retail: kind as typed…
        ->not->toContain('Shipping — labor')                                 // …never suffixed
        ->toContain('General construction — labor &amp; material (incl. extras)')
        // NAME and POSITION are sworn in front of a notary, so they print as
        // captioned blanks with the highlighted asterisk — never pre-filled.
        ->toContain('(NAME)')
        ->toContain('(POSITION)')
        ->toContain('req-star')
        ->not->toContain('TEST USER')
        ->not->toContain('SECRETARY');
});

it('gives a sub waiver its own affidavit with the sub-contract math, GC affidavit stays single-line', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    // PMG's sub-contract with the GC is on file as a bid.
    Bid::withoutGlobalScopes()->create(['project_id' => $project->id, 'vendor_id' => $sub->id, 'amount' => 45000, 'type' => 1]);

    $makeWaiver = fn (int $claimantId, float $amount) => \App\Models\LienWaiver::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $gc->id,
        'vendor_id' => $claimantId,
        'project_id' => $project->id,
        'type' => \App\Enums\LienWaiverType::ConditionalProgress,
        'status' => \App\Enums\LienWaiverStatus::Draft,
        'amount' => $amount,
        'exceptions_amount' => 0,
        'through_date' => now()->toDateString(),
        'jurisdiction' => 'US-IL',
        'notes' => json_encode(['payer' => ['name' => 'Mark & Gail Brodson']]),
        'created_by_user_id' => $user->id,
    ]);

    $render = function (\App\Models\LienWaiver $waiver, $vendor, array $affidavit) use ($project) {
        return view('pdf.lien-waiver', [
            'waiver' => $waiver,
            'project' => $project,
            'vendor' => $vendor,
            'payerVendor' => null,
            'payerOverride' => ['name' => 'Mark & Gail Brodson', 'address' => '', 'city_state_zip' => ''],
            'check' => null,
            'payment' => null,
            'signatures' => collect(),
            'isSigned' => false,
            'isDraft' => true,
            'isSubWaiver' => $waiver->isSubWaiver(),
            'projectCounty' => 'Cook',
            'amountWords' => 'Amount In Words',
            'affidavit' => $affidavit,
        ])->render();
    };

    // Sub waiver: affidavit present, recites PMG's numbers (45k contract,
    // 30k received to date, 15k balance) — never the GC's contract.
    $subWaiver = $makeWaiver($sub->id, 30000);
    $subHtml = $render($subWaiver, $sub->fresh(), [
        'original_contract' => 45000.0, 'extras' => 0.0, 'contract_total' => 45000.0,
        'amount_paid' => 30000.0, 'this_payment' => 0.0, 'balance_due' => 15000.0,
    ]);

    expect($subHtml)
        ->toContain('CONTRACTOR&rsquo;S AFFIDAVIT')
        ->toContain('NOTARY PUBLIC')
        ->toContain('45,000.00')
        ->toContain('15,000.00')
        ->not->toContain('560,208.50');

    // GC waiver: affidavit is the single GS line only — no sub names on it.
    $gcWaiver = $makeWaiver($gc->id, 60000);
    $gcHtml = $render($gcWaiver, $gc, [
        'original_contract' => 559258.50, 'extras' => 950.0, 'contract_total' => 560208.50,
        'amount_paid' => 120000.0, 'this_payment' => 60000.0, 'balance_due' => 380208.50,
    ]);

    expect($gcHtml)
        ->toContain('CONTRACTOR&rsquo;S AFFIDAVIT')
        ->toContain('560,208.50')
        ->not->toContain(e($sub->fresh()->business_name))
        ->not->toContain(e($retail->fresh()->business_name));
});

it('computes the sub affidavit from money paid to the sub, not owner draws', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    Bid::withoutGlobalScopes()->create(['project_id' => $project->id, 'vendor_id' => $sub->id, 'amount' => 45000, 'type' => 1]);

    $waiver = \App\Models\LienWaiver::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $gc->id,
        'vendor_id' => $sub->id,
        'project_id' => $project->id,
        'type' => \App\Enums\LienWaiverType::ConditionalProgress,
        'status' => \App\Enums\LienWaiverStatus::Draft,
        'amount' => 30000, // covers the recorded $30k expense/check
        'exceptions_amount' => 0,
        'through_date' => now()->toDateString(),
        'jurisdiction' => 'US-IL',
        'created_by_user_id' => $user->id,
    ]);

    // A manual "to date" waiver documents money already in hand: the $30k PMG
    // received is prior/paid-to-date, this payment is $0, balance = 15k. The
    // owner's $120k in draws must play no part in a sub's affidavit.
    $method = new ReflectionMethod(\App\Support\LienWaiverDocumentGenerator::class, 'buildAffidavitContext');
    $affidavit = $method->invoke(null, $waiver->fresh()->load('project', 'payment'));

    expect($affidavit['contract_total'])->toBe(45000.0)
        ->and($affidavit['amount_paid'])->toBe(30000.0)  // received to date
        ->and($affidavit['this_payment'])->toBe(0.0)     // no new payment now
        ->and($affidavit['balance_due'])->toBe(15000.0)
        ->and($affidavit['amount_paid'])->not->toBe(120000.0);
});

it('falls back to money-paid as the contract when a sub has no bid on file', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    // No bid recorded for PMG — only the $30k expense/check from the fixture.
    $waiver = \App\Models\LienWaiver::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $gc->id,
        'vendor_id' => $sub->id,
        'project_id' => $project->id,
        'type' => \App\Enums\LienWaiverType::ConditionalProgress,
        'status' => \App\Enums\LienWaiverStatus::Draft,
        'amount' => 30000,
        'exceptions_amount' => 0,
        'through_date' => now()->toDateString(),
        'jurisdiction' => 'US-IL',
        'created_by_user_id' => $user->id,
    ]);

    $method = new ReflectionMethod(\App\Support\LienWaiverDocumentGenerator::class, 'buildAffidavitContext');
    $affidavit = $method->invoke(null, $waiver->fresh()->load('project', 'payment'));

    // Contract stands in as the $30k received; balance nets to $0.
    expect($affidavit['contract_total'])->toBe(30000.0)
        ->and($affidavit['amount_paid'])->toBe(30000.0)
        ->and($affidavit['this_payment'])->toBe(0.0)
        ->and($affidavit['balance_due'])->toBe(0.0)
        ->and($affidavit['extras'])->toBe(0.0);

    // A recorded bid overrides the fallback and drives a real balance.
    Bid::withoutGlobalScopes()->create(['project_id' => $project->id, 'vendor_id' => $sub->id, 'amount' => 45000, 'type' => 1]);
    $affidavit = $method->invoke(null, $waiver->fresh()->load('project', 'payment'));

    expect($affidavit['contract_total'])->toBe(45000.0)
        ->and($affidavit['amount_paid'])->toBe(30000.0)
        ->and($affidavit['balance_due'])->toBe(15000.0);
});

it('suggests kinds of work from the trade list plus previously used kinds', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    // A past waiver used a custom kind — it should join the suggestions once,
    // even when the casing differs from a default entry.
    LienWaiver::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $gc->id, 'vendor_id' => $sub->id, 'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalProgress, 'status' => LienWaiverStatus::Signed,
        'amount' => 1000, 'exceptions_amount' => 0, 'through_date' => now()->toDateString(),
        'jurisdiction' => 'US-IL', 'created_by_user_id' => $user->id,
        'notes' => json_encode(['kind_of_work' => 'Finish carpentry & trim']),
    ]);
    LienWaiver::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $gc->id, 'vendor_id' => $sub->id, 'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalProgress, 'status' => LienWaiverStatus::Signed,
        'amount' => 1000, 'exceptions_amount' => 0, 'through_date' => now()->toDateString(),
        'jurisdiction' => 'US-IL', 'created_by_user_id' => $user->id,
        'notes' => json_encode(['kind_of_work' => 'carpentry']), // duplicate of the default, lowercased
    ]);

    $component = Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openSwornStatement');

    $options = $component->instance()->kindOfWorkOptions;

    expect($options)->toContain('Finish carpentry & trim')
        ->and($options)->toContain('Plumbing')
        ->and(collect($options)->filter(fn ($k) => strcasecmp($k, 'carpentry') === 0))->toHaveCount(1);

    $component->assertSee('Finish carpentry & trim');
});

it('saves selected kinds of work as WorkTypes and defaults the vendor to them next time', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    $component = Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openSwornStatement');

    $rows = collect($component->get('ssRows'));
    $pmgIndex = $rows->search(fn ($r) => $r['vendor_id'] === $sub->id);

    expect($rows[$pmgIndex]['kind'])->toBe('Carpentry'); // prefilled from the vendor's default work type

    $component
        ->set("ssRows.{$pmgIndex}.kind", 'Carpentry')
        ->set('ssThisPayment', '60,000')
        ->call('generateSwornStatement')
        ->assertHasNoErrors();

    // A WorkType record now exists for the tenant and is PMG's default.
    $workType = \App\Models\WorkType::where('belongs_to_vendor_id', $gc->id)
        ->where('name', 'Carpentry')->first();

    expect($workType)->not->toBeNull()
        ->and($sub->fresh()->work_type_id)->toBe($workType->id);

    // Reopening prefills PMG's kind from the saved default.
    $component->call('openSwornStatement');
    $freshRow = collect($component->get('ssRows'))->firstWhere('vendor_id', $sub->id);
    expect($freshRow['kind'])->toBe('Carpentry');

    // Re-selecting a differently-cased name reuses the record (no duplicate),
    // and a new kind switches the vendor's default.
    expect(\App\Models\WorkType::resolve($gc->id, 'carpentry')->id)->toBe($workType->id)
        ->and(\App\Models\WorkType::where('belongs_to_vendor_id', $gc->id)->count())->toBe(1);
});

it('gives retail claimants an affidavit and reserves waiver-only for material suppliers', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    $render = function ($claimant, $notes) use ($gc, $user, $project) {
        $waiver = LienWaiver::withoutGlobalScopes()->create([
            'belongs_to_vendor_id' => $gc->id, 'vendor_id' => $claimant->id, 'project_id' => $project->id,
            'type' => LienWaiverType::ConditionalProgress, 'status' => LienWaiverStatus::Draft,
            'amount' => 2791.70, 'exceptions_amount' => 0, 'through_date' => now()->toDateString(),
            'jurisdiction' => 'US-IL', 'notes' => json_encode($notes), 'created_by_user_id' => $user->id,
        ]);

        return view('pdf.lien-waiver', [
            'waiver' => $waiver,
            'project' => $project,
            'vendor' => $claimant->fresh(),
            'payerVendor' => Vendor::withoutGlobalScopes()->find($gc->id),
            'payerOverride' => null,
            'check' => null,
            'payment' => null,
            'signatures' => collect(),
            'isSigned' => false,
            'isDraft' => true,
            'isSubWaiver' => true,
            'projectCounty' => 'Cook',
            'amountWords' => 'Some Amount',
            'affidavit' => ['original_contract' => 0.0, 'extras' => 0.0, 'contract_total' => 2791.70, 'amount_paid' => 2791.70, 'this_payment' => 0.0, 'balance_due' => 0.0],
        ])->render();
    };

    // Retail claimant (haulers, rentals): swears the affidavit like everyone
    // else on the statement, and "to furnish" names the work type as typed
    // (no "labor & material" suffix — they supply goods/services).
    $retailHtml = $render($retail, ['manual' => true, 'source' => 'sworn_statement', 'kind_of_work' => 'Debris removal', 'retail' => true]);

    expect($retailHtml)
        ->toContain('WAIVER OF LIEN TO DATE')
        ->toContain('to furnish</span><span class="uline">Debris removal')
        ->toContain('CONTRACTOR&rsquo;S AFFIDAVIT')
        ->toContain('NOTARY PUBLIC');

    // MATERIAL suppliers are the waiver-only case: goods furnished, so there
    // is no contract math to swear to.
    $materialHtml = $render($retail, ['manual' => true, 'source' => 'sworn_statement', 'kind_of_work' => 'Lumber', 'material' => true]);

    expect($materialHtml)
        ->toContain('WAIVER OF LIEN TO DATE')
        ->not->toContain('CONTRACTOR&rsquo;S AFFIDAVIT')
        ->not->toContain('NOTARY PUBLIC');

    // A trade sub keeps the affidavit, and its furnish line names the trade.
    $subHtml = $render($sub, ['manual' => true, 'source' => 'sworn_statement', 'kind_of_work' => 'Carpentry']);

    expect($subHtml)
        ->toContain('to furnish</span><span class="uline">Carpentry')
        ->toContain('CONTRACTOR&rsquo;S AFFIDAVIT')
        ->toContain('NOTARY PUBLIC');
});

it('persists the generated statement, lists it as GCSS, and types waivers LWTD/FLW', function () {
    Illuminate\Support\Facades\Storage::fake('files');

    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    $component = Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openSwornStatement')
        ->set('ssThisPayment', '60,000')
        ->call('generateSwornStatement')
        ->assertHasNoErrors();

    // The statement is stored: DB row + PDF on disk.
    $statement = \App\Models\SwornStatement::first();
    expect($statement)->not->toBeNull()
        ->and($statement->project_id)->toBe($project->id)
        ->and((float) $statement->this_payment)->toBe(60000.0)
        ->and($statement->path)->not->toBeNull();
    Illuminate\Support\Facades\Storage::disk('files')->assertExists($statement->path);

    // The table shows the GCSS row and types waivers as LWTD.
    $component->assertSee('GCSS')
        ->assertSee('General Contractor Sworn Statement')
        ->assertSee('LWTD')
        ->assertSee('Lien Waiver to Date');

    // A final waiver types as FLW.
    LienWaiver::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $gc->id, 'vendor_id' => $sub->id, 'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalFinal, 'status' => LienWaiverStatus::Draft,
        'amount' => 15000, 'exceptions_amount' => 0, 'through_date' => now()->toDateString(),
        'jurisdiction' => 'US-IL', 'created_by_user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->assertSee('FLW')
        ->assertSee('Final Lien Waiver');

    // Deleting the draw soft-deletes the statement AND every waiver stamped
    // with it; stored PDFs stay on disk (everything is recoverable).
    $path = $statement->path;
    $drawWaiverIds = LienWaiver::withoutGlobalScopes()
        ->where('sworn_statement_id', $statement->id)->pluck('id');
    expect($drawWaiverIds)->not->toBeEmpty();

    // Delete goes through a confirmation modal that spells out the impact.
    $component->call('confirmDeleteDraw', $statement->id)
        ->assertSet('showDrawDelete', true)
        ->assertSee('Delete Draw')
        ->assertSee('sworn statement (GCSS)')
        ->call('deleteConfirmedDraw')
        ->assertSet('showDrawDelete', false);

    expect(\App\Models\SwornStatement::find($statement->id))->toBeNull()
        ->and(\App\Models\SwornStatement::withTrashed()->find($statement->id)?->trashed())->toBeTrue();
    Illuminate\Support\Facades\Storage::disk('files')->assertExists($path);
    expect(LienWaiver::withoutGlobalScopes()->whereIn('id', $drawWaiverIds)->whereNull('deleted_at')->count())->toBe(0);
});

it('sends each created sub waiver for signature immediately', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openSwornStatement')
        ->set('ssThisPayment', '60,000')
        ->call('generateSwornStatement')
        ->assertHasNoErrors();

    $pmgWaiver = LienWaiver::withoutGlobalScopes()
        ->where('project_id', $project->id)->where('vendor_id', $sub->id)->first();
    $gcWaiver = LienWaiver::withoutGlobalScopes()
        ->where('project_id', $project->id)->where('vendor_id', $gc->id)->first();

    // The sub's waiver is dispatched for signature; the GC's own waiver isn't
    // emailed (it's signed in-app).
    Illuminate\Support\Facades\Bus::assertDispatched(
        \App\Jobs\SendLienWaiverSigningRequestJob::class,
        fn ($job) => $job->lienWaiverId === $pmgWaiver->id,
    );
    Illuminate\Support\Facades\Bus::assertNotDispatched(
        \App\Jobs\SendLienWaiverSigningRequestJob::class,
        fn ($job) => $job->lienWaiverId === $gcWaiver->id,
    );
});

it('emails the signing request in the recipient vendor\'s preferred language', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    // PMG's contact speaks Polish.
    $polishUser = User::query()->create([
        'first_name' => 'Piotr', 'last_name' => 'Majewski',
        'email' => 'piotr-' . Str::random(6) . '@example.test',
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
        'preferred_language' => 'Polish',
    ]);
    $polishUser->vendors()->attach($sub->id, ['role_id' => 1, 'is_employed' => true]);

    $waiver = LienWaiver::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $gc->id, 'vendor_id' => $sub->id, 'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalProgress, 'status' => LienWaiverStatus::Draft,
        'amount' => 30000, 'exceptions_amount' => 0, 'through_date' => now()->toDateString(),
        'jurisdiction' => 'US-IL', 'created_by_user_id' => $user->id,
    ]);

    Illuminate\Support\Facades\Mail::fake();

    // The job runs on the queue with no authenticated user.
    auth()->logout();

    (new \App\Jobs\SendLienWaiverSigningRequestJob($waiver->id))->handle();

    Illuminate\Support\Facades\Mail::assertSent(
        \App\Mail\LienWaiverSigningRequest::class,
        fn ($mail) => $mail->locale === 'pl',
    );

    expect($waiver->fresh()->status)->toBe(LienWaiverStatus::Sent);
});

it('flags the signing email as high priority', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);
    auth()->logout();

    $waiver = LienWaiver::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $gc->id, 'vendor_id' => $sub->id, 'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalProgress, 'status' => LienWaiverStatus::Draft,
        'amount' => 30000, 'exceptions_amount' => 0, 'through_date' => now()->toDateString(),
        'jurisdiction' => 'US-IL', 'created_by_user_id' => $user->id,
    ]);

    // The testing mailer is the array transport — send and inspect the built
    // Symfony message's priority headers.
    Illuminate\Support\Facades\Mail::to('inbox@example.test')
        ->send(new \App\Mail\LienWaiverSigningRequest($waiver, 'PMG'));

    $message = collect(Illuminate\Support\Facades\Mail::getSymfonyTransport()->messages())
        ->last()?->getOriginalMessage();

    expect($message->getPriority())->toBe(\Symfony\Component\Mime\Email::PRIORITY_HIGHEST)
        ->and($message->getHeaders()->get('Importance')?->getBodyAsString())->toBe('High');
});

it('renders the step-by-step signing email in each language', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    $waiver = LienWaiver::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $gc->id, 'vendor_id' => $sub->id, 'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalProgress, 'status' => LienWaiverStatus::Draft,
        'amount' => 30000, 'exceptions_amount' => 0, 'through_date' => now()->toDateString(),
        'jurisdiction' => 'US-IL', 'created_by_user_id' => $user->id,
    ]);

    // Mail renders in queue context (no auth) — and rendering builds the PDF
    // attachment, which needs the vendor visible outside VendorScope.
    auth()->logout();

    $render = function (string $locale) use ($waiver) {
        app()->setLocale($locale);

        return (new \App\Mail\LienWaiverSigningRequest($waiver->fresh(), 'PMG'))->render();
    };

    // English: the four steps, few words, no e-sign button — plus the maps
    // link on the address, the urgency line, and the localized sign-up CTA.
    $en = $render('en');
    expect($en)
        ->toContain('Print')
        ->toContain('yellow')
        ->toContain('rgba(253, 224, 71, 0.5)')          // *-only highlight, 50% transparent
        ->toContain('Notarize')
        ->toContain('Title or Position')
        ->toContain('Owner')
        ->toContain('President')
        ->toContain('notarized copy in person')
        ->toContain('SIGNATURE')
        ->toContain('/projects/')                        // address links to the project
        ->toContain('Call us ASAP')
        ->toContain('pay us until your notarized waiver is in')
        ->toContain('Join Hive Contractors')             // sign-up CTA card
        ->toContain(route('registration'))
        ->toContain(route('login'))
        ->not->toContain('4 Quick Steps')
        ->not->toContain('sign electronically');

    // Subject: contractor | Lien Waiver Request | address.
    app()->setLocale('en');
    $subject = (new \App\Mail\LienWaiverSigningRequest($waiver->fresh(), 'PMG'))->envelope()->subject;
    expect($subject)->toContain('Lien Waiver Request')->toContain('3154 Violet Ln');

    // Polish and Spanish render their translations with localized CTA text.
    expect($render('pl'))->toContain('Wydrukuj')->toContain('notarialnie')->toContain('Dołącz do Hive');
    expect($render('es'))->toContain('Imprima')->toContain('notario')->toContain('Únase a Hive');

    app()->setLocale('en');
});

it('never emails deleted, cancelled or signed waivers, and replies route to the GC', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    $make = fn () => LienWaiver::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $gc->id, 'vendor_id' => $sub->id, 'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalProgress, 'status' => LienWaiverStatus::Draft,
        'amount' => 30000, 'exceptions_amount' => 0, 'through_date' => now()->toDateString(),
        'jurisdiction' => 'US-IL', 'created_by_user_id' => $user->id,
    ]);

    auth()->logout();
    Illuminate\Support\Facades\Mail::fake();

    // Deleted between dispatch and execution → no email, stays trashed Draft.
    $deleted = $make();
    $deleted->delete();
    (new \App\Jobs\SendLienWaiverSigningRequestJob($deleted->id))->handle();

    // Cancelled → no email, status not regressed.
    $cancelled = $make();
    $cancelled->forceFill(['status' => LienWaiverStatus::Cancelled])->save();
    (new \App\Jobs\SendLienWaiverSigningRequestJob($cancelled->id))->handle();

    Illuminate\Support\Facades\Mail::assertNothingSent();
    expect($cancelled->fresh()->status)->toBe(LienWaiverStatus::Cancelled);

    // A live Draft sends — with reply-to pointed at the waivers@ ingest
    // mailbox, so replying with the notarized scan feeds the barcode matcher.
    $live = $make();
    (new \App\Jobs\SendLienWaiverSigningRequestJob($live->id))->handle();

    Illuminate\Support\Facades\Mail::assertSent(
        \App\Mail\LienWaiverSigningRequest::class,
        fn ($mail) => $mail->replyToEmail === config('nylas.waivers_email'),
    );
});

it('sends a lingering pre-existing draft when the statement is generated', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    // A manually created Draft that was never sent.
    $existing = LienWaiver::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $gc->id, 'vendor_id' => $sub->id, 'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalProgress, 'status' => LienWaiverStatus::Draft,
        'amount' => 30000, 'exceptions_amount' => 0, 'through_date' => now()->toDateString(),
        'jurisdiction' => 'US-IL', 'created_by_user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openSwornStatement')
        ->set('ssThisPayment', '60,000')
        ->call('generateSwornStatement')
        ->assertHasNoErrors();

    // No duplicate created, but the lingering draft went out.
    expect(LienWaiver::withoutGlobalScopes()->where('project_id', $project->id)->where('vendor_id', $sub->id)->count())->toBe(1);
    Illuminate\Support\Facades\Bus::assertDispatched(
        \App\Jobs\SendLienWaiverSigningRequestJob::class,
        fn ($job) => $job->lienWaiverId === $existing->id,
    );
});

it('downloads the whole draw package as one merged PDF', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    // The download route sits behind auth + registered + vendor.access.
    $user->forceFill(['registration' => ['registered' => true]])->saveQuietly();
    $gc->forceFill(['registration' => ['registered' => true]])->saveQuietly();
    $this->actingAs($user);

    // Generate the package: GCSS (stored on the real files disk) + waivers.
    Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openSwornStatement')
        ->set('ssThisPayment', '60,000')
        ->call('generateSwornStatement')
        ->assertHasNoErrors();

    expect(LienWaiver::withoutGlobalScopes()->where('project_id', $project->id)->count())->toBeGreaterThanOrEqual(2);

    $statement = \App\Models\SwornStatement::query()->latest('id')->firstOrFail();

    // Waivers are stamped with the draw they belong to.
    expect(LienWaiver::withoutGlobalScopes()->where('project_id', $project->id)->whereNull('sworn_statement_id')->count())->toBe(0);

    $response = $this->get(route('sworn-statements.download-package', $statement));

    $response->assertSuccessful();
    expect($response->headers->get('content-disposition'))->toContain('draw-package-mark-gail-brodson-3154-violet-ln-draw-1');

    // The merged binary is a valid PDF with multiple pages (GCSS + GC waiver + PMG waiver).
    $binary = file_get_contents($response->getFile()->getPathname());
    expect(str_starts_with($binary, '%PDF'))->toBeTrue()
        ->and(preg_match_all('/\/Type\s*\/Page\b/', $binary))->toBeGreaterThanOrEqual(3);

    // A Signed waiver ships its stored executed document (notarized scan),
    // not a blank re-render. Store a 2-page "scan" for PMG's normally 1-page
    // waiver: the package page count must grow by exactly one page.
    $basePages = preg_match_all('/\/Type\s*\/Page\b/', $binary);

    $pmgWaiver = LienWaiver::withoutGlobalScopes()
        ->where('project_id', $project->id)->where('vendor_id', $sub->id)->firstOrFail();

    $waiverPdf = \App\Support\LienWaiverDocumentGenerator::generate($pmgWaiver)['binary'];
    $twoPageScan = \App\Support\PdfMerger::merge([$waiverPdf, $waiverPdf]);
    \Illuminate\Support\Facades\Storage::disk('files')
        ->put("lien-waivers/{$project->id}/{$pmgWaiver->id}/scan-signed.pdf", $twoPageScan);
    $pmgWaiver->forceFill([
        'status' => LienWaiverStatus::Signed,
        'signed_path' => "lien-waivers/{$project->id}/{$pmgWaiver->id}/scan-signed.pdf",
        'signed_at' => now(),
    ])->saveQuietly();

    $signedResponse = $this->get(route('sworn-statements.download-package', $statement));
    $signedResponse->assertSuccessful();
    $signedBinary = file_get_contents($signedResponse->getFile()->getPathname());
    expect(str_starts_with($signedBinary, '%PDF'))->toBeTrue()
        ->and(preg_match_all('/\/Type\s*\/Page\b/', $signedBinary))->toBe($basePages + 1);
});

it('marks no-contract waivers OPEN and never rows with a real balance', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    // No contract typed and none on file → waiver to date (fallback = paid).
    $component = Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openSwornStatement');
    $component->set('ssThisPayment', '60,000')
        ->call('generateSwornStatement')
        ->assertHasNoErrors();

    $waiver = LienWaiver::withoutGlobalScopes()
        ->where('project_id', $project->id)->where('vendor_id', $sub->id)->first();

    expect($waiver->type)->toBe(LienWaiverType::ConditionalProgress);

    auth()->logout();
    $render = fn ($affidavit) => view('pdf.lien-waiver', [
        'waiver' => $waiver,
        'project' => $project,
        'vendor' => $sub->fresh(),
        'payerVendor' => Vendor::withoutGlobalScopes()->find($gc->id),
        'payerOverride' => null,
        'check' => null,
        'payment' => null,
        'signatures' => collect(),
        'isSigned' => false,
        'isDraft' => true,
        'isSubWaiver' => true,
        'projectCounty' => 'Cook',
        'amountWords' => 'Thirty Thousand',
        'affidavit' => $affidavit,
    ])->render();

    // The fallback affidavit (contract stands in as money paid) marks OPEN.
    $fallbackHtml = $render(['original_contract' => 30000.0, 'extras' => 0.0, 'contract_total' => 30000.0, 'amount_paid' => 30000.0, 'this_payment' => 0.0, 'balance_due' => 0.0]);
    expect($fallbackHtml)
        ->toContain('$30,000.00<br>OPEN')
        ->toContain('$0.00<br>OPEN');

    // A real outstanding balance (contract > paid) never shows OPEN.
    $balanceHtml = $render(['original_contract' => 45000.0, 'extras' => 0.0, 'contract_total' => 45000.0, 'amount_paid' => 30000.0, 'this_payment' => 0.0, 'balance_due' => 15000.0]);
    expect($balanceHtml)->not->toContain('<br>OPEN');
});

it('syncs auto-final type and kind onto a pre-existing draft when regenerated', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    // First run: no contract on file — waiver to date.
    $component = Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openSwornStatement');
    $component->set('ssThisPayment', '60,000')->call('generateSwornStatement')->assertHasNoErrors();

    $waiver = LienWaiver::withoutGlobalScopes()
        ->where('project_id', $project->id)->where('vendor_id', $sub->id)->first();
    expect($waiver->type)->toBe(LienWaiverType::ConditionalProgress);

    // Second run: typing the contract at exactly what was paid → the draft
    // flips to a FINAL waiver automatically, kind synced too.
    $component->call('openSwornStatement');
    $pmgIndex = collect($component->get('ssRows'))->search(fn ($r) => $r['vendor_id'] === $sub->id);
    $component
        ->set("ssRows.{$pmgIndex}.contract", '30,000.00')
        ->set("ssRows.{$pmgIndex}.kind", 'Framing & Concrete')
        ->set('ssThisPayment', '60,000')
        ->call('generateSwornStatement')
        ->assertHasNoErrors();

    $waivers = LienWaiver::withoutGlobalScopes()
        ->where('project_id', $project->id)->where('vendor_id', $sub->id)->get();
    $notes = json_decode($waivers->first()->notes, true);

    expect($waivers)->toHaveCount(1)
        ->and($waivers->first()->type)->toBe(LienWaiverType::UnconditionalFinal)
        ->and($notes['kind_of_work'] ?? '')->toBe('Framing & Concrete');
});

it('automatically generates a FINAL lien waiver when a real contract is fully paid', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    $component = Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openSwornStatement');

    $pmgIndex = collect($component->get('ssRows'))->search(fn ($r) => $r['vendor_id'] === $sub->id);

    // Typed contract = money received → automatic final, no checkbox.
    $component
        ->set("ssRows.{$pmgIndex}.contract", '30,000.00')
        ->set('ssThisPayment', '60,000')
        ->call('generateSwornStatement')
        ->assertHasNoErrors();

    $waiver = LienWaiver::withoutGlobalScopes()
        ->where('project_id', $project->id)->where('vendor_id', $sub->id)->first();

    expect($waiver->type)->toBe(LienWaiverType::UnconditionalFinal);

    // The final waiver prints PAID IN FULL and never carries OPEN markers.
    auth()->logout();
    $waiverHtml = view('pdf.lien-waiver', [
        'waiver' => $waiver,
        'project' => $project,
        'vendor' => $sub->fresh(),
        'payerVendor' => Vendor::withoutGlobalScopes()->find($gc->id),
        'payerOverride' => null,
        'check' => null,
        'payment' => null,
        'signatures' => collect(),
        'isSigned' => false,
        'isDraft' => true,
        'isSubWaiver' => true,
        'projectCounty' => 'Cook',
        'amountWords' => null,
        'affidavit' => ['original_contract' => 30000.0, 'extras' => 0.0, 'contract_total' => 30000.0, 'amount_paid' => 30000.0, 'this_payment' => 0.0, 'balance_due' => 0.0],
    ])->render();

    expect($waiverHtml)
        ->toContain('PAID IN FULL')
        ->not->toContain('<br>OPEN');
});

it('creates waiver-only documents for material retail suppliers, skips plain retail', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    // Home Depot: retail, classified Materials for the P&L — and, like the
    // real store, no email on file.
    $materials = Vendor::query()->create([
        'business_name' => 'Home Depot',
        'business_type' => 'Retail',
        'sheets_type' => 'Materials',
        'address' => '100 Depot Dr', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);
    $materials->forceFill(['work_type_id' => \App\Models\WorkType::resolve($gc->id, 'Materials')->id])->save();
    Expense::withoutGlobalScopes()->create([
        'project_id' => $project->id, 'vendor_id' => $materials->id, 'belongs_to_vendor_id' => $gc->id,
        'amount' => 405.88, 'date' => '2026-06-20', 'created_by_user_id' => $user->id,
    ]);

    $component = Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openSwornStatement');

    // Materials suppliers are NOT preselected — check Home Depot on manually.
    $rows = collect($component->get('ssRows'));
    $materialsIndex = $rows->search(fn ($r) => $r['vendor_id'] === $materials->id);
    expect($rows[$materialsIndex]['include'])->toBeFalse()
        ->and($rows->firstWhere('vendor_id', $retail->id)['include'])->toBeFalse();

    $component->set("ssRows.{$materialsIndex}.include", true)
        ->set('ssThisPayment', '60,000')
        ->call('generateSwornStatement')
        ->assertHasNoErrors();

    // The material supplier got a waiver; plain retail (FedEx) did not.
    $materialWaiver = LienWaiver::withoutGlobalScopes()
        ->where('project_id', $project->id)->where('vendor_id', $materials->id)->first();

    expect($materialWaiver)->not->toBeNull()
        ->and((float) $materialWaiver->amount)->toBe(405.88)
        ->and(LienWaiver::withoutGlobalScopes()->where('project_id', $project->id)->where('vendor_id', $retail->id)->exists())->toBeFalse();

    // No email on file → the job still dispatches; it falls back to emailing
    // the draw's creator with a forward banner. PMG (has email) goes straight
    // to the vendor.
    Illuminate\Support\Facades\Bus::assertDispatched(
        \App\Jobs\SendLienWaiverSigningRequestJob::class,
        fn ($job) => $job->lienWaiverId === $materialWaiver->id,
    );
    $pmgWaiver = LienWaiver::withoutGlobalScopes()
        ->where('project_id', $project->id)->where('vendor_id', $sub->id)->first();
    Illuminate\Support\Facades\Bus::assertDispatched(
        \App\Jobs\SendLienWaiverSigningRequestJob::class,
        fn ($job) => $job->lienWaiverId === $pmgWaiver->id,
    );

    // Its document is the waiver only — no affidavit, no notary block.
    auth()->logout();
    $html = view('pdf.lien-waiver', [
        'waiver' => $materialWaiver,
        'project' => $project,
        'vendor' => $materials->fresh(),
        'payerVendor' => Vendor::withoutGlobalScopes()->find($gc->id),
        'payerOverride' => null,
        'check' => null,
        'payment' => null,
        'signatures' => collect(),
        'isSigned' => false,
        'isDraft' => true,
        'isSubWaiver' => true,
        'projectCounty' => 'Cook',
        'amountWords' => 'Four Hundred Five',
        'affidavit' => ['original_contract' => 405.88, 'extras' => 0.0, 'contract_total' => 405.88, 'amount_paid' => 405.88, 'this_payment' => 0.0, 'balance_due' => 0.0],
    ])->render();

    expect($html)
        ->toContain('WAIVER OF LIEN TO DATE')
        ->not->toContain('CONTRACTOR&rsquo;S AFFIDAVIT')
        ->not->toContain('NOTARY PUBLIC');
});

it('requires the draw amount before generating', function () {
    [$gc, $sub, $retail, $user, $project] = swornStatementFixtures();
    $this->actingAs($user);

    Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openSwornStatement')
        ->call('generateSwornStatement')
        ->assertHasErrors(['ssThisPayment'])
        ->assertSee('Enter the amount the owner is paying with this draw.')
        ->assertSet('showSwornStatement', true);

    // Zero isn't a draw either — at least $0.01.
    Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openSwornStatement')
        ->set('ssThisPayment', '0')
        ->call('generateSwornStatement')
        ->assertHasErrors(['ssThisPayment'])
        ->assertSee('The draw amount must be at least $0.01.');

    // Missing amount AND missing kind surface together in one click.
    $component = Livewire::actingAs($user)
        ->test(Index::class, ['project' => $project])
        ->call('openSwornStatement');
    $pmgIndex = collect($component->get('ssRows'))->search(fn ($r) => $r['vendor_id'] === $sub->id);
    $component->set("ssRows.{$pmgIndex}.kind", '')
        ->call('generateSwornStatement')
        ->assertHasErrors(['ssThisPayment', "ssRows.{$pmgIndex}.kind"])
        ->assertSee('Enter the amount the owner is paying with this draw.')
        ->assertSee('Select the type of work.');
});
