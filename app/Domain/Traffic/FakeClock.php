<?php

namespace App\Domain\Traffic;

use DateTimeImmutable;

final class FakeClock implements Clock
{
    public function __construct(
        private DateTimeImmutable $now,
    ) {}

    public static function at(string|DateTimeImmutable $time): self
    {
        return new self(is_string($time) ? new DateTimeImmutable($time) : $time);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }

    public function set(string|DateTimeImmutable $time): void
    {
        $this->now = is_string($time) ? new DateTimeImmutable($time) : $time;
    }
}
