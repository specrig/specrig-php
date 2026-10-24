<?php

declare(strict_types=1);

namespace Specrig\Symfony;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Specrig\Logged;

final class Logs implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(private readonly LoggerInterface $inner, private readonly Harness $harness)
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->harness->logged(new Logged((string) $level, (string) $message, $context));
        $this->inner->log($level, $message, $context);
    }
}
