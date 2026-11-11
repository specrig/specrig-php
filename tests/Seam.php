<?php

declare(strict_types=1);

namespace Specrig\Tests;

use Specrig\Databases;

final class Seam implements Databases
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly \Closure $migrate)
    {
    }

    public function migrate(): void
    {
        ($this->migrate)();
    }

    public function bookkeeping(): array
    {
        return [];
    }

    public function refuse(string $address, string $refusing): void
    {
        $this->calls[] = 'refuse';
    }

    public function fail(string $address, \Throwable $error): void
    {
        $this->calls[] = "fail {$error->getMessage()}";
    }

    public function heal(string $address, string $refusing): void
    {
        $this->calls[] = 'heal';
    }
}
