<?php

namespace App\Domain\Traffic;

final class CommandRequest
{
    private function __construct(
        public readonly bool $returnToAutomatic,
        public readonly ?Direction $direction,
    ) {}

    public static function manualGreen(Direction $direction): self
    {
        return new self(false, $direction);
    }

    public static function returnToAutomatic(): self
    {
        return new self(true, null);
    }
}
