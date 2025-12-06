<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation\NTPServer;

use Innmind\TimeContinuum\{
    Clock as RealClock,
    PointInTime,
    Period,
};
use Innmind\Immutable\SideEffect;

/**
 * @internal
 */
final class Clock
{
    /**
     * @param \Closure(PointInTime): PointInTime $delta
     */
    private function __construct(
        private RealClock $clock,
        private \Closure $delta,
        private ?Period $advance,
        private Clock\Speed $speed,
    ) {
    }

    /**
     * @internal
     *
     * @param ?int<2, 10> $clockSpeed
     */
    #[\NoDiscard]
    public static function of(
        RealClock $clock,
        ?PointInTime $now,
        ?int $clockSpeed,
    ): self {
        $realNow = $clock->now();
        $speed = Clock\Speed::of($now ?? $realNow, $clockSpeed);

        if (\is_null($now)) {
            $move = static fn(PointInTime $now): PointInTime => $now;
        } else if ($now->aheadOf($realNow)) {
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
            $speed,
        );
    }

    #[\NoDiscard]
    public function now(): PointInTime
    {
        $now = ($this->delta)($this->clock->now());

        if ($this->advance) {
            $now = $now->goForward($this->advance);
        }

        return ($this->speed)($now);
    }

    #[\NoDiscard]
    public function advance(Period $period): SideEffect
    {
        $this->advance = $this->advance?->add($period) ?? $period;

        return SideEffect::identity;
    }
}
