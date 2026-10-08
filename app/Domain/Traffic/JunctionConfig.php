<?php

namespace App\Domain\Traffic;

final class JunctionConfig
{
    /**
     * @param  list<string>  $directions
     * @param  list<PhaseConfig>  $phases
     */
    public function __construct(
        public readonly array $directions,
        public readonly array $phases,
        public readonly int $minGreenSeconds,
        public readonly int $yellowSeconds,
        public readonly int $allRedSeconds,
    ) {}

    public static function default(): self
    {
        return new self(
            directions: ['NORTH', 'SOUTH', 'EAST', 'WEST'],
            phases: [
                new PhaseConfig(Phase::NORTH_SOUTH, ['NORTH', 'SOUTH'], 30),
                new PhaseConfig(Phase::EAST_WEST, ['EAST', 'WEST'], 30),
            ],
            minGreenSeconds: 10,
            yellowSeconds: 5,
            allRedSeconds: 2,
        );
    }

    public static function fromArray(array $data): self
    {
        $phases = [];
        foreach ($data['phases'] as $phase) {
            $phases[] = new PhaseConfig(
                Phase::from($phase['name']),
                $phase['green'],
                $phase['duration'],
            );
        }

        return new self(
            directions: $data['directions'],
            phases: $phases,
            minGreenSeconds: $data['timings']['min_green'],
            yellowSeconds: $data['timings']['yellow'],
            allRedSeconds: $data['timings']['all_red'],
        );
    }

    /**
     * @return list<Direction>
     */
    public function directions(): array
    {
        return array_map(fn (string $direction) => Direction::from($direction), $this->directions);
    }

    /**
     * @return list<Phase>
     */
    public function phaseSequence(): array
    {
        return array_map(fn (PhaseConfig $phase) => $phase->phase, $this->phases);
    }

    /**
     * @return list<Direction>
     */
    public function greensFor(Phase $phase): array
    {
        foreach ($this->phases as $phaseConfig) {
            if ($phaseConfig->phase === $phase) {
                return $phaseConfig->greenDirections();
            }
        }

        return [];
    }

    public function phaseFor(Direction $direction): ?Phase
    {
        foreach ($this->phases as $phaseConfig) {
            if (in_array($direction, $phaseConfig->greenDirections(), true)) {
                return $phaseConfig->phase;
            }
        }

        return null;
    }

    public function duration(Phase $phase): int
    {
        foreach ($this->phases as $phaseConfig) {
            if ($phaseConfig->phase === $phase) {
                return $phaseConfig->duration;
            }
        }

        return 0;
    }

    /**
     * @return array<string, string> direction value => signal color value
     */
    public function desiredSignalsFor(Step $step): array
    {
        $signals = [];
        foreach ($this->directions() as $direction) {
            $signals[$direction->value] = SignalColor::RED->value;
        }

        if ($step !== Step::ALL_RED) {
            $color = $step->isYellow() ? SignalColor::YELLOW : SignalColor::GREEN;
            foreach ($this->greensFor($step->phase()) as $direction) {
                $signals[$direction->value] = $color->value;
            }
        }

        return $signals;
    }
}
