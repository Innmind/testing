<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation\Machine;

use Innmind\Testing\Machine\{
    ProcessBuilder,
    CLI,
};
use Innmind\Server\Control\Server\{
    Command,
    Process,
};
use Innmind\Filesystem\File\Content;
use Innmind\Immutable\{
    Map,
    Attempt,
};

/**
 * @internal
 */
final class Processes
{
    /**
     * @psalm-mutation-free
     *
     * @param Map<non-empty-string, CLI> $executables
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
     * @psalm-pure
     * @internal
     *
     * @param Map<non-empty-string, CLI> $executables
     * @param Map<string, string> $environment
     */
    #[\NoDiscard]
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
    #[\NoDiscard]
    public function run(Command $command): Attempt
    {
        // todo handle timeouts, by hijacking the Process::halt() to make sure
        // we don't go over the threshold ? (but how ?)

        return $this->dispatch($command->internal());
    }

    /**
     * @return Attempt<Process>
     */
    private function dispatch(Command\Implementation $command): Attempt
    {
        if ($command instanceof Command\Definition) {
            return $this->doRun($command);
        }

        if ($command instanceof Command\Pipe) {
            return $this
                ->dispatch($command->a())
                ->map(
                    static fn($process) => $process
                        ->output()
                        ->map(static fn($chunk) => $chunk->data()),
                )
                ->map(Content::ofChunks(...))
                ->map(static fn($output) => $command->b()->withInput($output))
                ->flatMap($this->dispatch(...));
        }

        throw new \LogicException(\sprintf(
            'Unknown command implementation %s',
            $command::class,
        ));
    }

    /**
     * @return Attempt<Process>
     */
    private function doRun(Command\Definition $command): Attempt
    {
        $executable = $command->executable();
        /**
         * @psalm-suppress MixedAgument
         * @psalm-suppress PossiblyNullFunctionCall
         * @psalm-suppress InaccessibleMethod
         * @var Command
         */
        $command = (\Closure::bind(
            static fn(): Command => new Command($command),
            null,
            Command::class,
        ))();

        // todo handle output redirection

        return $this
            ->executables
            ->get($executable)
            ->attempt(static fn() => new \RuntimeException( // todo return a failed process with exit code 127 (zsh behaviour)
                \sprintf(
                    'Failed to start %s command',
                    $executable,
                ),
            ))
            ->map(fn($app) => $app(
                $command,
                ProcessBuilder::new(++$this->lastPid),
                $this->os->unwrap(),
                $this->environment,
            )->build());
    }
}
