<?php

declare(strict_types=1);

namespace Specrig\Symfony;

use Specrig\Databases as Seam;
use Specrig\Postgres\Address;

final class Databases implements Seam
{
    public function __construct(private readonly Harness $harness)
    {
    }

    // A project with Doctrine's migrations ships its schema as them; one without,
    // as its mapping.
    public function migrate(): void
    {
        $commands = $this->harness->commands();
        $run = match (true) {
            in_array('doctrine:migrations:migrate', $commands, true) => ['doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration'],
            in_array('doctrine:schema:create', $commands, true) => ['doctrine:schema:create'],
            default => throw new \LogicException("{$this->harness->service()} has neither Doctrine's migrations nor its schema tool to make its database with"),
        };
        [$code, $output] = $this->harness->console(...$run);
        if ($code !== 0) {
            throw new \RuntimeException(trim($output));
        }
    }

    public function bookkeeping(): array
    {
        return ['doctrine_migration_versions'];
    }

    public function refuse(string $address, string $refusing): void
    {
        $this->harness->faulted($address, Address::parse($refusing), null);
        $this->close();
    }

    public function fail(string $address, \Throwable $error): void
    {
        $this->harness->faulted($address, null, $error);
    }

    private function close(): void
    {
        $doctrine = $this->harness->get('doctrine');
        foreach ($doctrine->getConnections() as $connection) {
            $connection->close();
        }
    }
}
