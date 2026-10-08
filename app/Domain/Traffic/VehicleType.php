<?php

namespace App\Domain\Traffic;

enum VehicleType: string
{
    case EMERGENCY = 'EMERGENCY';

    case TRUCK = 'TRUCK';

    case FORKLIFT = 'FORKLIFT';

    case EMPLOYEE_VEHICLE = 'EMPLOYEE_VEHICLE';

    public function weight(): int
    {
        return match ($this) {
            self::EMERGENCY => 100,
            self::TRUCK => 5,
            self::FORKLIFT => 3,
            self::EMPLOYEE_VEHICLE => 1,
        };
    }
}
