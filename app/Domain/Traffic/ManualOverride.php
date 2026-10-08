<?php

namespace App\Domain\Traffic;

use DateTimeImmutable;

final class ManualOverride
{
    public const LIFETIME_SECONDS = 300;

    public function __construct(
        public readonly Direction $direction,
        public readonly DateTimeImmutable $modeStartedAt,
        public readonly DateTimeImmutable $expiresAt,
    ) {}

    public static function request(Direction $direction, DateTimeImmutable $now): self
    {
        return new self(
            $direction,
            $now,
            $now->modify('+'.self::LIFETIME_SECONDS.' seconds'),
        );
    }
}
