<?php

namespace App\Application\Traffic;

enum SensorEventType: string
{
    case VEHICLE_ARRIVED = 'VEHICLE_ARRIVED';

    case VEHICLE_CLEARED = 'VEHICLE_CLEARED';
}
