<?php

declare(strict_types=1);

namespace Specrig\Symfony\Dbal;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware as DriverMiddleware;

final class Middleware implements DriverMiddleware
{
    public function wrap(Driver $driver): Driver
    {
        return new Faulty($driver);
    }
}
