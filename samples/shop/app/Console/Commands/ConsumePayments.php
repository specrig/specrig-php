<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;

final class ConsumePayments extends Command
{
    protected $signature = 'payments:consume';

    protected $description = 'Marks the orders billing reports paid';

    public function handle(AbstractConnection $connection): int
    {
        $channel = $connection->channel();
        $channel->exchange_declare('billing', 'topic', false, true, false);
        $channel->queue_declare('shop.invoices-paid', false, true, false, false);
        $channel->queue_bind('shop.invoices-paid', 'billing', 'invoices.paid');
        $channel->basic_consume('shop.invoices-paid', '', false, false, false, false, function (AMQPMessage $message) {
            $paid = json_decode($message->getBody(), true);
            if (!is_int($paid['order'] ?? null)) {
                $message->reject(false);
                return;
            }
            Order::whereKey($paid['order'])->update(['paid' => true]);
            $message->ack();
        });
        while ($channel->is_consuming()) {
            $channel->wait();
        }
        return self::SUCCESS;
    }
}
