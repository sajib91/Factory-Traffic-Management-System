<?php

namespace App\Domain\Traffic;

final class SignallingGuard
{
    /**
     * @param  array<string, string>  $signals  direction value => signal color value
     */
    public static function assertNoConflictingGreens(array $signals, JunctionConfig $config): void
    {
        $greenPhase = null;

        foreach ($signals as $directionValue => $colorValue) {
            if ($colorValue !== SignalColor::GREEN->value) {
                continue;
            }

            $direction = Direction::tryFrom($directionValue);
            if ($direction === null) {
                continue;
            }

            $phase = $config->phaseFor($direction);
            if ($phase === null) {
                continue;
            }

            if ($greenPhase !== null && $phase !== $greenPhase) {
                throw new ConflictingGreenException(
                    "Conflicting GREEN detected for direction {$directionValue} (phase {$phase->value}) and phase {$greenPhase->value}.",
                );
            }

            $greenPhase = $phase;
        }
    }
}
