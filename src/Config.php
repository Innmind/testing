<?php
declare(strict_types = 1);

namespace Innmind\Testing;

use Innmind\Testing\Machine\{
    ProcessBuilder,
    State\Clock as SimulatedClock,
};
use Innmind\OperatingSystem\Config as OSConfig;
use Innmind\Server\Control\Server\Command;
use Innmind\TimeWarp\Halt;
use Innmind\TimeContinuum\{
    Clock,
    PointInTime,
};
use Innmind\Immutable\{
    Map,
    Attempt,
};

/**
 * @internal
 */
final class Config
{
    /**
     * @param Map<non-empty-string, callable(Command, ProcessBuilder): ProcessBuilder> $executables
     */
    public function __construct(
        private ?PointInTime $start,
        private Map $executables,
    ) {
    }

    public function __invoke(OSConfig $config): OSConfig
    {
        $clock = $config->clock();
        $simulatedClock = SimulatedClock::of(
            $clock,
            $this->start ?? $clock->now(),
        );

        return $config
            ->withClock(Clock::via(static fn() => $simulatedClock->now()))
            ->haltProcessVia(Halt::via(static fn($period) => Attempt::result(
                $simulatedClock->halt($period),
            )));
    }
}
