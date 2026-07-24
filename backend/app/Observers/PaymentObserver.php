<?php

namespace App\Observers;

use App\Models\Payment;
use App\Support\Concerns\DispatchesBidirectionalCloudSync;

class PaymentObserver
{
    use DispatchesBidirectionalCloudSync;

    public function created(Payment $payment): void
    {
        $this->dispatchBidirectionalSync('Payment', 'created', $payment);
    }

    public function updated(Payment $payment): void
    {
        $this->dispatchBidirectionalSync('Payment', 'updated', $payment);
    }

    public function deleted(Payment $payment): void
    {
        $this->dispatchBidirectionalSync('Payment', 'deleted', $payment);
    }
}
