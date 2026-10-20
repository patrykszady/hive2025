<?php

use App\Http\Controllers\CompanyEmailController;
use App\Http\Controllers\ReceiptController;
use App\Livewire\Expenses\ExpenseCreate;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Expenses\QuickReceiptCapture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\HandleComponents;

uses(RefreshDatabase::class);

/**
 * "Upload Receipt" on /expenses used to create the expense the moment the
 * receipt was read (expense 28901, 2026-10-20). It now opens the new-expense
 * form filled in from the receipt, and nothing is saved until Create.
 *
 * Like tests/Feature/Security/T4/ExpenseOwnershipTest.php, these call the
 * mounted instance directly: the form view's nested splits component cannot
 * be re-rendered inside Livewire::test().
 */
beforeEach(function (): void {
    Storage::fake('files');

    $this->vendor = Vendor::factory()->create();
    $this->vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $this->admin = User::factory()->create();
    $this->admin->forceFill(['primary_vendor_id' => $this->vendor->id, 'registration' => ['registered' => true]])->save();
    $this->vendor->users()->attach($this->admin->id, ['role_id' => 1]);

    $client = Client::factory()->create();
    $client->vendors()->attach($this->vendor->id);
    $this->project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Upload Receipt Project',
        'client_id' => $client->id,
        'address' => '1 Test St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
        'belongs_to_vendor_id' => $this->vendor->id,
    ]));
    $this->project->vendors()->attach($this->vendor->id, ['client_id' => $client->id]);

    $this->partialMock(ReceiptController::class, function ($mock): void {
        $mock->shouldReceive('extractReceipt')->andReturn([
            'fields' => ['total' => 48.17, 'transaction_date' => '2026-10-19', 'invoice_number' => 'INV-28901'],
        ]);
    });

    $this->filedReceipts = [];
    $this->partialMock(CompanyEmailController::class, function ($mock): void {
        $mock->shouldReceive('saveExpenseReceipt')->andReturnUsing(function ($expenseId, $ocr, $filename) {
            $this->filedReceipts[] = ['expense_id' => $expenseId, 'filename' => $filename];
        });
    });
});

/**
 * Runs a component method the way a request would: Flux's $this->modal()
 * looks the component up on Livewire's component stack.
 */
function withinUploadReceiptComponent(ExpenseCreate $component, Closure $call): mixed
{
    HandleComponents::$componentStack[] = $component;

    try {
        return $call($component);
    } finally {
        array_pop(HandleComponents::$componentStack);
    }
}

function uploadReceiptComponent(User $user): ExpenseCreate
{
    $component = Livewire::actingAs($user)->test(ExpenseCreate::class)->instance();
    $component->upload_file = UploadedFile::fake()->create('receipt.jpg', 10, 'image/jpeg');

    withinUploadReceiptComponent($component, fn (ExpenseCreate $c) => $c->uploadReceipt());

    return $component;
}

it('opens the new-expense form filled from the receipt without creating an expense', function (): void {
    $before = Expense::withoutGlobalScopes()->count();

    $component = uploadReceiptComponent($this->admin);

    expect(Expense::withoutGlobalScopes()->count())->toBe($before)
        ->and($this->filedReceipts)->toBe([])
        ->and($component->getErrorBag()->isEmpty())->toBeTrue()
        ->and((float) $component->form->amount)->toBe(48.17)
        ->and($component->form->date)->toBe('2026-10-19')
        ->and($component->form->invoice)->toBe('INV-28901')
        ->and($component->form->pending_receipt)->not->toBeNull()
        ->and($component->view_text['button_text'])->toBe('Create')
        ->and($component->view_text['form_submit'])->toBe('save')
        ->and(Storage::disk('files')->allFiles('_temp_ocr'))->toHaveCount(1);
});

it('files the read receipt under the expense once Create is pressed', function (): void {
    $component = uploadReceiptComponent($this->admin);
    $merchant = Vendor::factory()->create();

    $component->form->vendor_id = $merchant->id;
    $component->form->project_id = $this->project->id;
    withinUploadReceiptComponent($component, fn (ExpenseCreate $c) => $c->save());

    $expense = Expense::where('project_id', $this->project->id)->sole();

    expect((float) $expense->amount)->toBe(48.17)
        ->and((string) $expense->invoice)->toBe('INV-28901')
        ->and($this->filedReceipts)->toHaveCount(1)
        ->and($this->filedReceipts[0]['expense_id'])->toBe($expense->id)
        ->and($component->form->pending_receipt)->toBeNull()
        ->and(Storage::disk('files')->allFiles('_temp_ocr'))->toBe([]);
});

it('drops the read receipt when a different new expense is started instead', function (): void {
    $component = uploadReceiptComponent($this->admin);
    $token = $component->form->pending_receipt;

    withinUploadReceiptComponent($component, fn (ExpenseCreate $c) => $c->newExpense(null));

    expect($component->form->pending_receipt)->toBeNull()
        ->and(app(QuickReceiptCapture::class)->release($token, $this->admin->id))->toBeNull()
        ->and(Storage::disk('files')->allFiles('_temp_ocr'))->toBe([]);
});

it('never hands a read receipt to another user', function (): void {
    $component = uploadReceiptComponent($this->admin);
    $otherUser = User::factory()->create();

    expect(app(QuickReceiptCapture::class)->release($component->form->pending_receipt, $otherUser->id))->toBeNull()
        ->and(app(QuickReceiptCapture::class)->release($component->form->pending_receipt, $this->admin->id))->not->toBeNull();
});
