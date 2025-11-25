<?php
declare(strict_types = 1);

namespace Innmind\Testing;

use Innmind\Testing\Machine\{
    ProcessBuilder,
    State\Clock as SimulatedClock,
};
use Innmind\OperatingSystem\Config as OSConfig;
use Innmind\Server\Control\{
    Server,
    Server\Command,
};
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
        $processes = 2;
        $clock = $config->clock();
        $simulatedClock = SimulatedClock::of(
            $clock,
            $this->start ?? $clock->now(),
        );
        $executables = $this->executables;

        return $config
            ->withClock(Clock::via(static fn() => $simulatedClock->now()))
            ->haltProcessVia(Halt::via(static fn($period) => Attempt::result(
                $simulatedClock->halt($period),
            )))
            ->useServerControl(Server::via(
                static function($command) use ($executables, &$processes) {
                    // todo build proper api in package
                    /**
                     * @psalm-suppress PossiblyNullFunctionCall
                     * @psalm-suppress UndefinedThisPropertyFetch
                     * @psalm-suppress MixedReturnStatement
                     * @var non-empty-string
                     */
                    $executable = (\Closure::bind(
                        fn(): string => $this->executable,
                        $command,
                        Command::class,
                    ))();
                    ++$processes;

                    return $executables
                        ->get($executable)
                        ->match(
                            static fn($build) => Attempt::result($build($command, ProcessBuilder::new($processes)))
                                ->map(static fn($builder) => $builder->build()),
                            static fn() => Attempt::error(new \RuntimeException( // todo return a failed process instead ?
                                \sprintf(
                                    'Failed to start %s command',
                                    $executable,
                                ),
                            )),
                        );
                },
            ));
    }
}
