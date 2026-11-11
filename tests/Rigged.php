<?php

declare(strict_types=1);

namespace Specrig\Tests;

use Specrig\Database;
use Specrig\Databases;
use Specrig\Http\Client;
use Specrig\Redis;
use Specrig\Rig;

final class Rigged extends Rig
{
    public function __construct(
        public readonly ?Database $database = null,
        public readonly ?Redis $cache = null,
        private readonly ?Databases $seam = null,
    ) {
        parent::__construct();
    }

    public function service(): string
    {
        return 'specrig';
    }

    public function get(string $id): mixed
    {
        throw new \LogicException('a Rigged service has no container');
    }

    public function http(): Client
    {
        throw new \LogicException('a Rigged service serves nothing');
    }

    public function run(string|object $job): void
    {
        throw new \LogicException('a Rigged service runs nothing');
    }

    public function command(string $name, string ...$args): int
    {
        throw new \LogicException('a Rigged service has no commands');
    }

    public function work(): array
    {
        return [];
    }

    public function databases(): Databases
    {
        return $this->seam ?? throw new \LogicException('this Rigged service has no database seam');
    }

    protected function framework(): array
    {
        return [];
    }

    protected function start(): void
    {
    }

    protected function keepRunning(string $command): void
    {
    }

    protected function stop(): void
    {
    }
}
