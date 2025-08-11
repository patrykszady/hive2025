<?php

namespace App\Livewire\Payments;

use App\Models\Payment;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

class PaymentShow extends Component
{
    use AuthorizesRequests;
    public Payment $payment;
    
    public function mount(Payment $payment)
    {
        $this->payment = $payment;
    }
    
    #[Title('Payment')]
    public function render()
    {
        $this->authorize('view', $this->payment);
        return view('livewire.payments.show');
    }
}
