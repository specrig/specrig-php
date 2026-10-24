<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;

final class ConsumeLetters extends Command
{
    public const SENT = 'letters.sent';

    protected $signature = 'letters:consume';

    public function handle(AbstractConnection $connection): void
    {
        $channel = $connection->channel();
        $channel->queue_bind('gateway.letters', 'crm', 'letters.*');
        $channel->basic_consume('gateway.letters', '', false, false, false, false, function (AMQPMessage $message) use ($channel) {
            $channel->basic_publish(new AMQPMessage('{}'), 'gateway', self::SENT);
            $message->ack();
        });
    }
}
