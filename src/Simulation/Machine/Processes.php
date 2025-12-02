<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation\Machine;

use Innmind\Testing\Machine\ProcessBuilder;
use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Server\Control\Server\{
    Command,
    Process,
};
use Innmind\Immutable\{
    Map,
    Attempt,
};

final class Processes
{
    /**
     * @param Map<non-empty-string, callable(Command, ProcessBuilder, OperatingSystem, Map<string, string>): ProcessBuilder> $executables
     * @param Map<string, string> $environment
     * @param int<2, max> $lastPid
     */
    private function __construct(
        private OS $os,
        private Map $executables,
        private Map $environment,
        private int $lastPid = 2,
    ) {
    }

    /**
     * @param Map<non-empty-string, callable(Command, ProcessBuilder, OperatingSystem, Map<string, string>): ProcessBuilder> $executables
     * @param Map<string, string> $environment
     */
    public static function new(
        OS $os,
        Map $executables,
        Map $environment,
    ): self {
        return new self($os, $executables, $environment);
    }

    /**
     * @return Attempt<Process>
     */
    public function run(Command $command): Attempt
    {
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

        return $this
            ->executables
            ->get($executable)
            ->attempt(static fn() => new \RuntimeException( // todo return a failed process instead ?
                \sprintf(
                    'Failed to start %s command',
                    $executable,
                ),
            ))
            ->map(fn($builder) => $builder(
                $command,
                ProcessBuilder::new(++$this->lastPid),
                $this->os->unwrap(),
                $this->environment,
            )->build());
    }
}
