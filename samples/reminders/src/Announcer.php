<?php

namespace App;

use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;

final class Announcer
{
    public function __construct(private readonly AbstractConnection $connection)
    {
    }

    public function sent(int $order, int $amount): void
    {
        $channel = $this->connection->channel();
        $channel->exchange_declare('reminders', 'topic', false, true, false);
        $channel->basic_publish(new AMQPMessage(json_encode(['order' => $order, 'amount' => $amount]), ['content_type' => 'application/json']), 'reminders', 'reminders.sent');
        $channel->close();
    }
}
