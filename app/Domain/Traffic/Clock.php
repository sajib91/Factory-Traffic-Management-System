<?php

namespace App\Domain\Traffic;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
