<?php

namespace App;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class Billing
{
    private function http(): PendingRequest
    {
        return Http::baseUrl(config('services.billing.url'))->acceptJson()->timeout(5);
    }

    public function toPay(int $order): int
    {
        return $this->http()->get("/invoices/{$order}")->throw()->json('amount');
    }

    public function invoice(int $order, int $amount): void
    {
        $this->http()->post('/invoices', ['order' => $order, 'amount' => $amount])->throw();
    }
}
