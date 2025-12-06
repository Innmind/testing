<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation;

use Innmind\TimeContinuum\{
    Clock as RealClock,
    PointInTime,
    Period,
};
use Innmind\Immutable\SideEffect;

final class NTPServer
{
    private function __construct(
        private NTPServer\Clock $clock,
    ) {
    }

    /**
     * @param ?int<2, 10> $clockSpeed
     */
    public static function new(
        ?PointInTime $start,
        ?int $clockSpeed,
    ): self {
        return new self(NTPServer\Clock::of(
            RealClock::live(),
            $start,
            $clockSpeed,
        ));
    }

    public function now(): PointInTime
    {
        return $this->clock->now();
    }

    /**
     * Either because a machine halted a process or a network call was made and
     * introduce a latency between machines
     */
    public function advance(Period $period): SideEffect
    {
        return $this->clock->advance($period);
    }
}
