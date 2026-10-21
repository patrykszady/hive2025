<?php

use App\Http\Controllers\CompanyEmailController;
use App\Models\Receipt;
use App\Models\Vendor;
use App\Services\NylasService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Studio 41's Receipt config is the whole domain, "@studio41.com", with no
 * subject — so every email from anyone there went to Hive Receipts. Two
 * "Good news! Items for your order … have arrived!" notices, forwarded by a
 * client, became $0 expenses (28366, 28898; 2026-10-20).
 */
beforeEach(function (): void {
    $studio41 = Vendor::factory()->create(['business_name' => 'Studio 41', 'business_type' => 'Retail']);
    $stickerMule = Vendor::factory()->create(['business_name' => 'Sticker Mule', 'business_type' => 'Retail']);

    $this->studio41Config = Receipt::forceCreate(['vendor_id' => $studio41->id, 'from_address' => '@studio41.com', 'from_subject' => null, 'receipt_type' => 1, 'options' => []]);
    $this->stickerMuleConfig = Receipt::forceCreate(['vendor_id' => $stickerMule->id, 'from_address' => 'help@stickermule.com', 'from_subject' => ['Your order is estimated to arrive by'], 'receipt_type' => 1, 'options' => []]);

    $this->nylas = Mockery::mock(NylasService::class);
    $this->controller = new class($this->nylas) extends CompanyEmailController
    {
        public function match(string $from, string $subject, ?array $message = null): ?Receipt
        {
            return $this->findMatchingReceipt($from, $subject, $message);
        }

        public function wouldForward(array $message): bool
        {
            return $this->validateMessageWouldMatchReceipt('grant-1', $message);
        }
    };
});

function catchAllInvoicePdf(string $filename = 'Invoice S2493998.pdf'): array
{
    return ['filename' => $filename, 'content_type' => 'application/pdf', 'is_inline' => false];
}

it('does not forward a client\'s forward of a Studio 41 "items have arrived" notice', function () {
    $this->nylas->shouldReceive('getMessage')->andReturn(['data' => ['body' => <<<'HTML'
        <p>Patryk, see message from Leslie below.</p>
        <p>From: Leslie Spitzer &lt;lspitzer@studio41.com&gt;<br>Sent: Monday, October 19, 2026 5:39 PM<br>To: Mark Brodson</p>
        <p>From: noreply@salesforce.com on behalf of No Reply From Studio41 &lt;no-reply@studio41.com&gt;</p>
        HTML]]);

    expect($this->controller->wouldForward([
        'id' => 'msg-1',
        'from' => [['email' => 'mark@resourcecommercial.net']],
        'subject' => 'Fw: Good news! Items for your order S2493998 - TUB/PR have arrived!',
        'attachments' => [],
    ]))->toBeFalse();
});

it('does not take a Studio 41 email that carries no receipt document', function () {
    expect($this->controller->match('lspitzer@studio41.com', 'Delivery for the Brodson powder room', ['attachments' => []]))->toBeNull()
        ->and($this->controller->match('lspitzer@studio41.com', 'Quote', ['attachments' => [['filename' => 'logo.png', 'content_type' => 'image/png', 'is_inline' => true]]]))->toBeNull();
});

it('still takes a Studio 41 email with its invoice attached', function () {
    expect($this->controller->match('no-reply@studio41.com', 'Invoice for order S2493998', ['attachments' => [catchAllInvoicePdf()]])?->id)
        ->toBe($this->studio41Config->id);
});

it('never takes an order-status notice from a whole-domain config, attachment or not', function (string $subject) {
    expect($this->controller->match('no-reply@studio41.com', $subject, ['attachments' => [catchAllInvoicePdf()]]))->toBeNull();
})->with([
    'Good news! Items for your order S2493998 - TUB/PR have arrived!',
    'Your order S2493998 has shipped',
    'Your order is out for delivery',
    'Your order is ready for pickup',
]);

it('leaves configs that name their subjects alone', function () {
    expect($this->controller->match('help@stickermule.com', 'Your order is estimated to arrive by Oct 25', ['attachments' => []])?->id)
        ->toBe($this->stickerMuleConfig->id);
});
