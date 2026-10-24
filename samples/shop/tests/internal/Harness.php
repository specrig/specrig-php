<?php

declare(strict_types=1);

namespace Tests\Internal;

use App\Console\Commands\ConsumePayments;
use Specrig\Amqp\Bus;
use Specrig\Backend;
use Specrig\Postgres;

final class Harness extends \Specrig\Laravel\Harness
{
    public function __construct(
        public readonly Backend $billing = new Backend('https://billing.shop.test', setting: 'BILLING_URL', contract: '../docs/billing.yaml', failure: 'Error'),
        public readonly Postgres $database = new Postgres('DB_URL'),
        public readonly Bus $bus = new Bus('RABBIT_URL'),
    ) {
        parent::__construct();
    }

    public function service(): string
    {
        return 'shop';
    }

    protected function constants(): array
    {
        return ['DB_CONNECTION' => 'pgsql'];
    }

    protected function running(): array
    {
        return [ConsumePayments::class];
    }
}
