<?php

namespace App\Http\Requests\Api\App;

/**
 * PUT /api/app/v1/payments/{payment} — editing a client payment validates
 * identically to creating one (see StorePaymentRequest, which this reuses
 * wholesale), mirroring App\Livewire\Forms\PaymentForm, whose update() runs
 * the same #[Rule] set store() does.
 */
class UpdatePaymentRequest extends StorePaymentRequest
{
}
