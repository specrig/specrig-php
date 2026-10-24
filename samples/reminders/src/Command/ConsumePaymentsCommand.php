<?php

namespace App\Command;

use App\PaidOrders;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:consume-payments', description: 'Keeps the orders billing reports paid')]
final class ConsumePaymentsCommand extends Command
{
    public function __construct(private readonly AbstractConnection $connection, private readonly PaidOrders $paid)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channel = $this->connection->channel();
        $channel->exchange_declare('billing', 'topic', false, true, false);
        $channel->queue_declare('reminders.invoices-paid', false, true, false, false);
        $channel->queue_bind('reminders.invoices-paid', 'billing', 'invoices.paid');
        $channel->basic_consume('reminders.invoices-paid', '', false, false, false, false, function (AMQPMessage $message) {
            $paid = json_decode($message->getBody(), true);
            if (!is_int($paid['order'] ?? null)) {
                $message->reject(false);
                return;
            }
            $this->paid->add($paid['order']);
            $message->ack();
        });
        $channel->consume();
        return Command::SUCCESS;
    }
}
