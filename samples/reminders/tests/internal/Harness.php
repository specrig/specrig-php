<?php

declare(strict_types=1);

namespace App\Tests\Internal;

use App\Command\ConsumePaymentsCommand;
use Specrig\Amqp\Bus;
use Specrig\Backend;
use Specrig\Postgres;
use Specrig\Redis;

final class Harness extends \Specrig\Symfony\Harness
{
    public function __construct(
        public readonly Backend $billing = new Backend('https://billing.reminders.test', setting: 'BILLING_URL', contract: '../docs/billing.yaml', failure: 'Error'),
        public readonly Postgres $database = new Postgres('DATABASE_URL'),
        public readonly Redis $cache = new Redis('REDIS_URL'),
        public readonly Bus $bus = new Bus('RABBIT_URL'),
    ) {
        parent::__construct();
    }

    public function service(): string
    {
        return 'reminders';
    }

    protected function constants(): array
    {
        return ['REMINDERS_LIMIT' => '1'];
    }

    protected function running(): array
    {
        return [ConsumePaymentsCommand::class];
    }
}
