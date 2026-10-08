<?php

namespace App\Domain\Traffic;

final class Scheduler
{
    public const STARVATION_THRESHOLD = 90.0;

    public const ANTI_FLAP_FACTOR = 1.2;

    public function __construct(
        private readonly JunctionConfig $config,
    ) {}

    public function shouldSwitchTo(Phase $current, QueueSnapshot $snapshot, int $elapsedGreen): bool
    {
        if ($elapsedGreen < $this->config->minGreenSeconds) {
            return false;
        }

        $other = $current->other();
        $otherQueue = $snapshot->queueFor($other);

        if ($otherQueue->isEmpty()) {
            return false;
        }

        if ($otherQueue->oldestWaitSeconds >= self::STARVATION_THRESHOLD) {
            return true;
        }

        if ($elapsedGreen >= $this->config->duration($current)) {
            return true;
        }

        return $this->score($otherQueue) > self::ANTI_FLAP_FACTOR * $this->score($snapshot->queueFor($current));
    }

    public function pickNextPhaseAfterAllRed(QueueSnapshot $snapshot): Phase
    {
        $best = null;
        $bestScore = -1.0;

        foreach ($this->config->phaseSequence() as $phase) {
            $queue = $snapshot->queueFor($phase);

            if ($queue->isEmpty()) {
                continue;
            }

            if ($queue->oldestWaitSeconds >= self::STARVATION_THRESHOLD) {
                return $phase;
            }

            $score = $this->score($queue);
            if ($score > $bestScore) {
                $best = $phase;
                $bestScore = $score;
            }
        }

        return $best ?? $this->config->phaseSequence()[0];
    }

    private function score(PhaseQueue $queue): float
    {
        return $queue->weightSum + 0.5 * $queue->oldestWaitSeconds;
    }
}
