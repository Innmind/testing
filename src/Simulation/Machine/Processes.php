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
     * @param Map<non-empty-string, callable(Command, ProcessBuilder, OperatingSystem): ProcessBuilder> $executables
     * @param int<2, max> $lastPid
     */
    private function __construct(
        private OS $os,
        private Map $executables,
        private int $lastPid = 2,
    ) {
    }

    /**
     * @param Map<non-empty-string, callable(Command, ProcessBuilder, OperatingSystem): ProcessBuilder> $executables
     */
    public static function new(OS $os, Map $executables): self
    {
        return new self($os, $executables);
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
            )->build());
    }
}
