<?php
declare(strict_types = 1);

namespace Innmind\Testing\Machine;

use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Server\Control\Server\Command;
use Innmind\Immutable\Map;

final class CLI
{
    /**
     * @psalm-mutation-free
     *
     * @param \Closure(Command, ProcessBuilder, OperatingSystem, Map<string, string>): ProcessBuilder $app
     */
    private function __construct(
        private \Closure $app, // todo support innmind/framework
    ) {
    }

    /**
     * @internal
     *
     * @param Map<string, string> $environment
     */
    #[\NoDiscard]
    public function __invoke(
        Command $command,
        ProcessBuilder $builder,
        OperatingSystem $os,
        Map $environment,
    ): ProcessBuilder {
        return ($this->app)(
            $command,
            $builder,
            $os,
            $environment,
        );
    }

    /**
     * @psalm-pure
     *
     * @param callable(Command, ProcessBuilder, OperatingSystem, Map<string, string>): ProcessBuilder $app
     */
    #[\NoDiscard]
    public static function of(callable $app): self
    {
        return new self(\Closure::fromCallable($app));
    }
}
