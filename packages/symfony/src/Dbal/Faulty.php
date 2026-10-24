<?php

declare(strict_types=1);

namespace Specrig\Symfony\Dbal;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Specrig\Symfony\Seams;

// A database a step made refuse is connected to where nothing listens, so the
// driver fails as it fails against a database that is down.
final class Faulty extends AbstractDriverMiddleware
{
    public function connect(#[\SensitiveParameter] array $params): Connection
    {
        [$refusing] = Seams::fault($params);
        if ($refusing !== null) {
            $params = ['host' => $refusing->host, 'port' => $refusing->port] + $params;
        }
        return new Failing(parent::connect($params), $params);
    }
}
