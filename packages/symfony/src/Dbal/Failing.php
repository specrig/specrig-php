<?php

declare(strict_types=1);

namespace Specrig\Symfony\Dbal;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Specrig\Symfony\Seams;

final class Failing extends AbstractConnectionMiddleware
{
    /** @param array<string, mixed> $params */
    public function __construct(Connection $connection, #[\SensitiveParameter] private readonly array $params)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        $this->check();
        return parent::prepare($sql);
    }

    public function query(string $sql): Result
    {
        $this->check();
        return parent::query($sql);
    }

    public function exec(string $sql): int|string
    {
        $this->check();
        return parent::exec($sql);
    }

    public function beginTransaction(): void
    {
        $this->check();
        parent::beginTransaction();
    }

    private function check(): void
    {
        [, $error] = Seams::fault($this->params);
        if ($error !== null) {
            throw $error;
        }
    }
}
