<?php

use App\Livewire\Checks\CheckShow;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\CheckImage;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CheckFrontCropper;
use App\Services\ContentUnderstandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// =========================================================================
// A check image added by hand on the check's page (Patryk, 2026-10-15): a
// photo, or Citibank's single-check PDF (details row, front, back, security
// tip). The front is cut out and read by the same analyzer as the
// statement scans.
// =========================================================================

/** A PDF laid out like Citibank's check image download, drawn with Ghostscript. */
function citiCheckPdf(bool $withCheck = true): string
{
    $ps = "/Helvetica findfont 10 scalefont setfont\n"
        ."72 740 moveto (Account     Check #     Post Date     Amount) show\n"
        ."72 720 moveto (Business Checking-4903   2470   Sep 02, 2025   -\$2,700.00) show\n"
        .($withCheck
            ? "2 setlinewidth 60 430 490 220 rectstroke\n80 600 moveto (GS CONSTRUCTION AND REMODELING) show\n"
                ."60 180 490 220 rectstroke\n"
            : '')
        ."72 150 moveto (Security Tip: Check Image files may be automatically saved) show\nshowpage\n";
    $psFile = tempnam(sys_get_temp_dir(), 'ps');
    $pdfFile = $psFile.'.pdf';
    file_put_contents($psFile, $ps);
    exec('gs -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pdfwrite -sOutputFile='.escapeshellarg($pdfFile).' '.escapeshellarg($psFile).' 2>/dev/null');
    $pdf = (string) @file_get_contents($pdfFile);
    @unlink($psFile);
    @unlink($pdfFile);

    return $pdf;
}

function uploadableCheck(): Check
{
    $company = Vendor::factory()->create(['business_type' => 'GC', 'business_name' => 'GS Construction']);
    $payee = Vendor::factory()->create(['business_type' => 'Sub', 'business_name' => 'Smartech Electric, Inc']);
    $user = User::query()->create([
        'first_name' => 'Check', 'last_name' => 'Admin', 'email' => 'checks-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999), 'primary_vendor_id' => $company->id,
    ]);
    $user->vendors()->attach($company->id, ['role_id' => 1]);
    test()->actingAs($user);
    $bank = \App\Models\Bank::create(['name' => 'Citibank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_5', 'plaid_item_id' => 'item-test']);
    $account = BankAccount::create(['vendor_id' => $company->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'plaid-'.uniqid(), 'type' => 'checking']);

    return Check::create([
        'check_type' => 'Check', 'check_number' => 2470, 'date' => '2025-08-29', 'amount' => 2700,
        'bank_account_id' => $account->id, 'vendor_id' => $payee->id, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $user->id,
    ]);
}

function analyzerReads(string $payee): void
{
    test()->mock(ContentUnderstandingService::class, fn ($mock) => $mock->shouldReceive('analyzeBinary')->andReturn(['result' => ['contents' => [['fields' => [
        'CheckInfo' => ['valueObject' => ['Payee' => ['valueString' => $payee]]],
        'CheckNumber' => ['valueObject' => ['Bank' => ['valueString' => '2470'], 'OnCheck' => ['valueString' => '2470']]],
        'BankInfo' => ['valueObject' => ['AccountNumber' => ['valueString' => '0800854903']]],
    ]]]]]));
}

beforeEach(function () {
    if (trim((string) shell_exec('command -v gs')) === '') {
        $this->markTestSkipped('Ghostscript is not installed here.');
    }
    Storage::fake('files');

    // The check page's children (its expense list and check form) are not
    // under test; stand-ins keep the page re-rendering after an upload.
    $standIn = new class extends \Livewire\Component
    {
        public function render(): string
        {
            return '<div></div>';
        }
    };
    Livewire::component('expenses.expense-index', $standIn::class);
    Livewire::component('checks.check-create', $standIn::class);
});

it('reads the details row of the bank\'s check image PDF', function () {
    expect(CheckFrontCropper::parseDetails("Account Check # Post Date Amount\n Business Checking-4903   2470   Sep 02, 2025   -\$2,700.00\n"))
        ->toBe(['account' => '4903', 'check_number' => 2470, 'posted_on' => '2025-09-02', 'amount' => 2700.0])
        ->and(CheckFrontCropper::parseDetails('no details here'))->toMatchArray(['check_number' => null, 'amount' => null]);
});

it('cuts out the front of the check, not the details row or the back', function () {
    $result = app(CheckFrontCropper::class)->fromPdf(citiCheckPdf());
    $front = imagecreatefromstring($result['png']);

    // The front is drawn 490 x 220 points: 1361 x 611 pixels at 200 dpi, plus a little padding.
    expect(imagesx($front))->toBeGreaterThan(1350)->toBeLessThan(1420)
        ->and(imagesy($front))->toBeGreaterThan(600)->toBeLessThan(660)
        ->and($result['details'])->toBe(['account' => '4903', 'check_number' => 2470, 'posted_on' => '2025-09-02', 'amount' => 2700.0])
        ->and(app(CheckFrontCropper::class)->fromPdf(citiCheckPdf(withCheck: false)))->toBeNull();
});

it('attaches an uploaded check image PDF to the check, keeps the PDF, and reads it with the check analyzer', function () {
    $check = uploadableCheck();
    analyzerReads('SMART TECH ELECTRIC');

    Livewire::test(CheckShow::class, ['check' => $check])
        ->set('checkImageFile', UploadedFile::fake()->createWithContent('Citibank Account Dashboard 2700.pdf', citiCheckPdf()))
        ->call('uploadCheckImage')
        ->assertHasNoErrors();

    $image = CheckImage::where('check_id', $check->id)->sole();
    expect($image)->toMatchArray([
        'image_filename' => '4903_2470_2700.00_2025-09-02.png',
        'check_number' => 2470,
        'account_number' => '0800854903',
        'payee' => 'SMART TECH ELECTRIC',
        'match_status' => CheckImage::STATUS_MANUAL,
        'belongs_to_vendor_id' => $check->belongs_to_vendor_id,
    ])
        ->and($image->check_date->toDateString())->toBe('2025-09-02')
        ->and($image->analyzed_at)->not->toBeNull()
        ->and(Storage::disk('files')->exists('checks/'.$image->image_filename))->toBeTrue()
        ->and(Storage::disk('files')->exists('checks/files/'.$image->statement_filename))->toBeTrue();
});

it('keeps a photo as uploaded, and refuses a PDF with no check in it', function () {
    $check = uploadableCheck();
    analyzerReads('SMART TECH ELECTRIC');
    $photo = imagecreatetruecolor(900, 400);
    ob_start();
    imagepng($photo);
    $png = (string) ob_get_clean();

    Livewire::test(CheckShow::class, ['check' => $check])
        ->set('checkImageFile', UploadedFile::fake()->createWithContent('check.png', $png))
        ->call('uploadCheckImage')
        ->assertHasNoErrors();
    $image = CheckImage::where('check_id', $check->id)->sole();
    expect($image->image_filename)->toBe('upload_2470_2700.00_2025-08-29.png')
        ->and($image->statement_filename)->toBeNull()
        ->and(Storage::disk('files')->get('checks/'.$image->image_filename))->toBe($png);

    Livewire::test(CheckShow::class, ['check' => $check])
        ->set('checkImageFile', UploadedFile::fake()->createWithContent('statement.pdf', citiCheckPdf(withCheck: false)))
        ->call('uploadCheckImage')
        ->assertHasErrors('checkImageFile');
    expect(CheckImage::where('check_id', $check->id)->count())->toBe(1);
});

it('lets only an admin of the company that wrote the check upload to it', function () {
    $check = uploadableCheck();
    $outsider = User::query()->create([
        'first_name' => 'Out', 'last_name' => 'Sider', 'email' => 'outsider-'.uniqid().'@example.test',
        'cell_phone' => '224'.rand(1000000, 9999999), 'primary_vendor_id' => Vendor::factory()->create()->id,
    ]);
    $this->actingAs($outsider);

    Livewire::test(CheckShow::class, ['check' => $check])->assertForbidden();
});
