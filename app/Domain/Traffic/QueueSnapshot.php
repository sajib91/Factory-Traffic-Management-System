<?php

namespace App\Domain\Traffic;

final class QueueSnapshot
{
    /**
     * @param  array<string, PhaseQueue>  $queues  phase value => queue
     */
    public function __construct(
        private readonly array $queues,
    ) {}

    public static function of(PhaseQueue $northSouth, PhaseQueue $eastWest): self
    {
        return new self([
            Phase::NORTH_SOUTH->value => $northSouth,
            Phase::EAST_WEST->value => $eastWest,
        ]);
    }

    public function queueFor(Phase $phase): PhaseQueue
    {
        return $this->queues[$phase->value] ?? PhaseQueue::empty();
    }
}
