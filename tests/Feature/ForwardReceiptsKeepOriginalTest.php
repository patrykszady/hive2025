<?php

use App\Http\Controllers\CompanyEmailController;
use App\Models\CompanyEmail;
use App\Models\ForwardedReceiptMessage;
use App\Models\Receipt;
use App\Models\Vendor;
use App\Services\NylasService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A receipt email forwarded into Hive Receipts stays in the mailbox it came
 * from (Patryk, 2026-10-21): it used to be moved out of the inbox, which was
 * also how the forwarder knew it had sent it. forwarded_receipt_messages
 * remembers that now.
 */
beforeEach(function (): void {
    $homeDepot = Vendor::factory()->create(['business_name' => 'Home Depot', 'business_type' => 'Retail']);
    $this->config = Receipt::forceCreate(['vendor_id' => $homeDepot->id, 'from_address' => 'HomeDepot@order.homedepot.com', 'from_subject' => ['Your Electronic Receipt'], 'receipt_type' => 1, 'options' => []]);

    $this->companyEmail = CompanyEmail::query()->create(['vendor_id' => 1, 'email' => 'patryk@example.test', 'grant_id' => 'grant-1']);
    $this->companyEmail->setRelation('receipts', new Collection([$this->config]));

    $this->message = [
        'id' => 'msg-1',
        'from' => [['email' => 'HomeDepot@order.homedepot.com']],
        'subject' => 'Your Electronic Receipt',
        'attachments' => [],
    ];
});

it('forwards a receipt email once, however often the forwarder runs', function () {
    $nylas = Mockery::mock(NylasService::class);
    $nylas->shouldReceive('getMessagesMatchingCriteria')->twice()->andReturn([$this->message]);
    $nylas->shouldReceive('sendForwardCopy')->once()->with('grant-1', 'msg-1', true, $this->companyEmail->id)
        ->andReturn(['status' => 200, 'data' => ['data' => ['id' => 'sent-1']]]);
    $nylas->shouldNotReceive('moveOriginalMessageToHiveFolder');

    $controller = new CompanyEmailController($nylas);
    $controller->processCompanyEmailForwarding($this->companyEmail, now()->subDays(30));
    $controller->processCompanyEmailForwarding($this->companyEmail, now()->subDays(30));

    expect(ForwardedReceiptMessage::query()->where('company_email_id', $this->companyEmail->id)->sole())
        ->message_id->toBe('msg-1')
        ->forwarded_message_id->toBe('sent-1');
});

it('tries again next run when a forward failed', function () {
    $nylas = Mockery::mock(NylasService::class);
    $nylas->shouldReceive('getMessagesMatchingCriteria')->twice()->andReturn([$this->message]);
    $nylas->shouldReceive('sendForwardCopy')->twice()->andReturn(['status' => 500, 'error' => 'Send failed']);

    $controller = new CompanyEmailController($nylas);
    $controller->processCompanyEmailForwarding($this->companyEmail, now()->subDays(30));
    $controller->processCompanyEmailForwarding($this->companyEmail, now()->subDays(30));

    expect(ForwardedReceiptMessage::query()->count())->toBe(0);
});

it('leaves the original where it is and clears only the copy in the receipts Sent folder', function () {
    $nylas = Mockery::mock(NylasService::class, [new GuzzleHttp\Client])->makePartial()->shouldAllowMockingProtectedMethods();
    $nylas->shouldReceive('getMessage')->andReturn(['data' => [
        'id' => 'msg-1',
        'subject' => 'Your Electronic Receipt',
        'body' => '<p>Thanks for shopping with us.</p>',
        'from' => [['email' => 'HomeDepot@order.homedepot.com']],
        'to' => [['email' => 'patryk@example.test']],
        'date' => 1760000000,
        'attachments' => [],
    ]]);
    $nylas->shouldReceive('makeNylasRequest')->once()->andReturn(['success' => true, 'status' => 200, 'data' => ['data' => ['id' => 'sent-1']]]);
    $nylas->shouldReceive('moveOrDeleteMessage')->once()
        ->with('sent-1', config('nylas.receipts_grant_id'), $this->companyEmail->id, config('nylas.receipts_deleted_folder_id'))
        ->andReturnTrue();

    $result = $nylas->sendForwardCopy('grant-1', 'msg-1', true, $this->companyEmail->id);

    expect($result['status'])->toBe(200);
});
