<?php

namespace App\Command;

use App\SendReminders;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:remind', description: 'Reminds every customer whose invoice is overdue')]
final class RemindCommand extends Command
{
    public function __construct(private readonly SendReminders $send)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('%d reminders queued', ($this->send)()));
        return Command::SUCCESS;
    }
}
