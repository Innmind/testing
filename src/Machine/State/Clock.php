<?php
declare(strict_types = 1);

namespace Innmind\Testing\Machine\State;

use Innmind\TimeContinuum\{
    Clock as RealClock,
    PointInTime,
    Period,
};
use Innmind\Immutable\SideEffect;

final class Clock
{
    /**
     * @param \Closure(PointInTime): PointInTime $delta
     */
    private function __construct(
        private RealClock $clock,
        private \Closure $delta,
        private ?Period $halt,
    ) {
    }

    public static function of(
        RealClock $clock,
        PointInTime $now,
    ): self {
        $realNow = $clock->now();

        if ($now->aheadOf($realNow)) {
            $delta = $now->elapsedSince($realNow)->asPeriod();
            $move = static fn(PointInTime $now): PointInTime => $now->goForward($delta);
        } else {
            $delta = $realNow->elapsedSince($now)->asPeriod();
            $move = static fn(PointInTime $now): PointInTime => $now->goBack($delta);
        }

        return new self(
            $clock,
            $move,
            null,
        );
    }

    public function now(): PointInTime
    {
        $now = ($this->delta)($this->clock->now());

        if ($this->halt) {
            $now = $now->goForward($this->halt);
        }

        return $now;
    }

    public function halt(Period $period): SideEffect
    {
        $this->halt = $this->halt?->add($period) ?? $period;

        return SideEffect::identity;
    }
}
