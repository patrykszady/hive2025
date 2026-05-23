<?php

use App\Livewire\Expenses\AutoReceipts;
use App\Models\AutoReceiptEmailBatch;
use App\Models\AutoReceiptEmailBatchItem;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function autoReceiptsUser(): User
{
    $vendor = Vendor::factory()->create();

    $user = User::query()->create([
        'first_name' => 'Auto',
        'last_name' => 'Receipts',
        'email' => 'auto-receipts-'.uniqid().'@example.test',
        'cell_phone' => '224' . rand(1000000, 9999999),
        'password' => null,
        'primary_vendor_id' => $vendor->id,
    ]);
    // role_id 1 = Admin
    $user->vendors()->attach($vendor->id, ['role_id' => 1]);

    return $user;
}

function makeReceiptFor(
    User $user,
    string $filename,
    int $minutesAgo,
    ?string $messageId = null,
    ?int $attachmentIndex = null,
    ?\Carbon\CarbonInterface $emailReceivedAt = null,
): ExpenseReceipts
{
    $expense = Expense::query()->create([
        'amount' => 12.34,
        'date' => now()->subDay(),
        'vendor_id' => $user->vendor->id,
        'belongs_to_vendor_id' => $user->vendor->id,
        'created_by_user_id' => $user->id,
    ]);

    $receipt = new ExpenseReceipts([
        'expense_id' => $expense->id,
        'receipt_filename' => $filename,
        'is_material_order' => false,
        'auto_receipt_message_id' => $messageId,
        'auto_receipt_attachment_index' => $attachmentIndex,
        'auto_receipt_email_received_at' => $emailReceivedAt,
    ]);
    $receipt->timestamps = false;
    $receipt->created_at = now()->subMinutes($minutesAgo);
    $receipt->updated_at = now()->subMinutes($minutesAgo);
    $receipt->save();

    if ($messageId && $attachmentIndex !== null) {
        $batch = AutoReceiptEmailBatch::firstOrCreate(
            ['message_id' => $messageId],
            [
                'company_email_id' => null,
                'belongs_to_vendor_id' => $user->vendor->id,
                'from_email' => 'noreply@print.epsonconnect.com',
                'subject' => 'Receipt Scans',
                'email_received_at' => $emailReceivedAt,
                'attachment_count' => 0,
                'processed_receipt_count' => 0,
            ],
        );

        AutoReceiptEmailBatchItem::updateOrCreate(
            ['batch_id' => $batch->id, 'attachment_index' => $attachmentIndex],
            ['expense_receipt_id' => $receipt->id],
        );
    }

    return $receipt;
}

it('lists auto-fetched receipts newest first and paginates one at a time', function () {
    $user = autoReceiptsUser();
    $this->actingAs($user);

    $receivedAt = now()->subHour();

    $oldest = makeReceiptFor($user, 'oldest.pdf', 300, 'msg-old', 1, $receivedAt->copy()->subMinutes(10));
    $middle = makeReceiptFor($user, 'middle.pdf', 200, 'msg-mid', 1, $receivedAt->copy()->subMinutes(5));
    $newest = makeReceiptFor($user, 'newest.pdf', 100, 'msg-new', 1, $receivedAt);

    Livewire::test(AutoReceipts::class)
        ->assertSet('position', 1)
        ->assertSee('Batch 1 | 1/1', false)
        ->assertSee('newest.pdf')
        ->call('next')
        ->assertSet('position', 2)
        ->assertSee('middle.pdf')
        ->call('next')
        ->assertSet('position', 3)
        ->assertSee('oldest.pdf')
        ->call('next')
        ->assertSet('position', 3) // clamps at end
        ->call('previous')
        ->assertSet('position', 2)
        ->assertSee('middle.pdf')
        ->call('goTo', 1)
        ->assertSee('newest.pdf');
});

it('shows an empty-state message when no receipts exist', function () {
    $user = autoReceiptsUser();
    $this->actingAs($user);

    Livewire::test(AutoReceipts::class)
        ->assertSee('No auto-fetched receipts found');
});

it('does not show receipts belonging to other vendors', function () {
    $userA = autoReceiptsUser();
    $userB = autoReceiptsUser();

    makeReceiptFor($userB, 'other-vendor.pdf', 5);

    $this->actingAs($userA);

    Livewire::test(AutoReceipts::class)
        ->assertSee('No auto-fetched receipts found')
        ->assertDontSee('other-vendor.pdf');
});

it('ignores receipts without auto-receipt message id', function () {
    $user = autoReceiptsUser();
    $this->actingAs($user);

    makeReceiptFor($user, 'manual-or-legacy.pdf', 5, null, null, null);

    Livewire::test(AutoReceipts::class)
        ->assertSee('No auto-fetched receipts found')
        ->assertDontSee('manual-or-legacy.pdf');
});

it('shows tabs for all receipts on the same expense', function () {
    $user = autoReceiptsUser();
    $this->actingAs($user);

    $expense = Expense::query()->create([
        'amount' => 44.44,
        'date' => now()->subDay(),
        'vendor_id' => $user->vendor->id,
        'belongs_to_vendor_id' => $user->vendor->id,
        'created_by_user_id' => $user->id,
    ]);

    $receivedAt = now()->subHour();

    $batch = AutoReceiptEmailBatch::create([
        'message_id' => 'msg-multi',
        'company_email_id' => null,
        'belongs_to_vendor_id' => $user->vendor->id,
        'from_email' => 'noreply@print.epsonconnect.com',
        'subject' => 'Receipt Scans',
        'email_received_at' => $receivedAt,
        'attachment_count' => 0,
        'processed_receipt_count' => 0,
    ]);

    foreach ([
        ['name' => 'expense-26828-r1.pdf', 'idx' => 1, 'minutes' => 12],
        ['name' => 'expense-26828-r2.pdf', 'idx' => 2, 'minutes' => 6],
    ] as $data) {
        $receipt = new ExpenseReceipts([
            'expense_id' => $expense->id,
            'receipt_filename' => $data['name'],
            'is_material_order' => false,
            'auto_receipt_message_id' => 'msg-multi',
            'auto_receipt_attachment_index' => $data['idx'],
            'auto_receipt_email_received_at' => $receivedAt,
        ]);
        $receipt->timestamps = false;
        $receipt->created_at = now()->subMinutes($data['minutes']);
        $receipt->updated_at = now()->subMinutes($data['minutes']);
        $receipt->save();

        AutoReceiptEmailBatchItem::create([
            'batch_id' => $batch->id,
            'expense_receipt_id' => $receipt->id,
            'attachment_index' => $data['idx'],
        ]);
    }

    Livewire::test(AutoReceipts::class)
        ->assertSee('Batch 1 | 1/2', false)
        ->assertSee('Receipt 1')
        ->assertSee('Receipt 2');
});

    it('groups receipts by source email and keeps attachment order within the batch', function () {
        $user = autoReceiptsUser();
        $this->actingAs($user);

        $receivedAt = now()->subHour();

        makeReceiptFor($user, 'email-1-attachment-3.pdf', 1, 'msg-abc', 3, $receivedAt);
        makeReceiptFor($user, 'email-1-attachment-2.pdf', 6, 'msg-abc', 2, $receivedAt);
        makeReceiptFor($user, 'email-1-attachment-1.pdf', 12, 'msg-abc', 1, $receivedAt);

        Livewire::test(AutoReceipts::class)
        ->assertSee('Batch 1 | 1/3', false)
        ->assertSee('email-1-attachment-1.pdf')
        ->call('next')
        ->assertSee('email-1-attachment-2.pdf')
        ->call('next')
        ->assertSee('email-1-attachment-3.pdf');
    });
