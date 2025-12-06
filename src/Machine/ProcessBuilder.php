<?php
declare(strict_types = 1);

namespace Innmind\Testing\Machine;

use Innmind\Server\Control\Server\{
    Process,
    Process\Success,
    Process\Signaled,
    Process\TimedOut,
    Process\Failed,
    Process\ExitCode,
    Process\Output\Chunk,
    Process\Output\Type,
    Process\Mock,
};
use Innmind\Immutable\{
    Sequence,
    Str,
};

/**
 * @todo move back to innmind/server-control
 */
final class ProcessBuilder
{
    /**
     * @psalm-mutation-free
     *
     * @param int<2, max> $pid
     */
    private function __construct(
        private int $pid,
        private Success|Signaled|TimedOut|Failed $result,
    ) {
    }

    /**
     * @internal
     * @psalm-pure
     *
     * @param int<2, max> $pid
     */
    #[\NoDiscard]
    public static function new(int $pid): self
    {
        /** @psalm-suppress ImpureMethodCall todo remove this line when in innmind/server-control */
        return new self($pid, new Success(Sequence::of()));
    }

    /**
     * @psalm-mutation-free
     *
     * @param Sequence<Chunk>|list<array{string, 'output'|'error'}> $output
     */
    #[\NoDiscard]
    public function success(Sequence|array|null $output = null): self
    {
        return new self(
            $this->pid,
            new Success(self::output($output)),
        );
    }

    /**
     * @psalm-mutation-free
     *
     * @param Sequence<Chunk>|list<array{string, 'output'|'error'}> $output
     */
    #[\NoDiscard]
    public function signaled(Sequence|array|null $output = null): self
    {
        return new self(
            $this->pid,
            new Signaled(self::output($output)),
        );
    }

    /**
     * @psalm-mutation-free
     *
     * @param Sequence<Chunk>|list<array{string, 'output'|'error'}> $output
     */
    #[\NoDiscard]
    public function timedOut(Sequence|array|null $output = null): self
    {
        return new self(
            $this->pid,
            new TimedOut(self::output($output)),
        );
    }

    /**
     * @psalm-mutation-free
     *
     * @param int<1, 255> $exitCode
     * @param Sequence<Chunk>|list<array{string, 'output'|'error'}> $output
     */
    #[\NoDiscard]
    public function failed(int $exitCode = 1, Sequence|array|null $output = null): self
    {
        return new self(
            $this->pid,
            new Failed(
                new ExitCode($exitCode),
                self::output($output),
            ),
        );
    }

    /**
     * @internal
     */
    #[\NoDiscard]
    public function build(): Process
    {
        $pid = $this->pid;
        $result = $this->result;

        /**
         * This a trick to not expose any mock contructor on the Process class.
         *
         * @psalm-suppress PossiblyNullFunctionCall
         * @psalm-suppress MixedReturnStatement
         * @psalm-suppress InaccessibleMethod
         */
        return (\Closure::bind(
            static fn() => new Process(new Mock($pid, $result)),
            null,
            Process::class,
        ))();
    }

    /**
     * @psalm-pure
     *
     * @param Sequence<Chunk>|list<array{string, 'output'|'error'}> $output
     *
     * @return Sequence<Chunk>
     */
    private static function output(Sequence|array|null $output = null): Sequence
    {
        if (\is_null($output)) {
            return Sequence::of();
        }

        if (\is_array($output)) {
            return Sequence::of(...$output)->map(static fn($pair) => Chunk::of(
                Str::of($pair[0]),
                match ($pair[1]) {
                    'output' => Type::output,
                    'error' => Type::error,
                },
            ));
        }

        return $output;
    }
}
