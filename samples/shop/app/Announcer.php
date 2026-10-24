<?php

namespace App;

use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;

final class Announcer
{
    public function __construct(private readonly AbstractConnection $connection)
    {
    }

    public function placed(int $order, int $amount): void
    {
        $this->publish('orders.placed', ['order' => $order, 'amount' => $amount]);
    }

    public function cancelled(int $order): void
    {
        $this->publish('orders.cancelled', ['order' => $order]);
    }

    private function publish(string $key, array $body): void
    {
        $channel = $this->connection->channel();
        $channel->exchange_declare('shop', 'topic', false, true, false);
        $channel->basic_publish(new AMQPMessage(json_encode($body), ['content_type' => 'application/json']), 'shop', $key);
        $channel->close();
    }
}
