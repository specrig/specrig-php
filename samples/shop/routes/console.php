<?php

use App\Announcer;
use App\Models\Order;
use Illuminate\Support\Facades\Artisan;

Artisan::command('orders:cancel {id}', function (Announcer $announcer, int $id) {
    if (Order::whereKey($id)->delete() === 0) {
        $this->error("no order {$id}");
        return 1;
    }
    $announcer->cancelled($id);
    return 0;
})->purpose('Cancels an order');
