<?php

declare(strict_types=1);

namespace Specrig\Symfony;

use Specrig\Clock as Harnessed;
use Symfony\Component\Clock\ClockInterface;

final class Clock implements ClockInterface
{
    public function __construct(private readonly Harnessed $clock, private readonly ?\DateTimeZone $zone = null)
    {
    }

    public function now(): \DateTimeImmutable
    {
        $now = $this->clock->now();
        return $this->zone === null ? $now : $now->setTimezone($this->zone);
    }

    public function sleep(float|int $seconds): void
    {
        if ($this->clock->stopped() === null) {
            usleep((int) ($seconds * 1e6));
            return;
        }
        $this->clock->advance(sprintf('+%d microseconds', (int) ($seconds * 1e6)));
    }

    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        return new self($this->clock, is_string($timezone) ? new \DateTimeZone($timezone) : $timezone);
    }
}
