<?php

namespace App\Jobs;

use App\Billing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class InvoiceOrder implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $order, public readonly int $amount)
    {
    }

    public function handle(Billing $billing): void
    {
        $billing->invoice($this->order, $this->amount);
    }
}
