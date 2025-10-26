<?php
declare(strict_types = 1);

namespace Innmind\Testing;

use Innmind\OperatingSystem\{
    OperatingSystem,
    Factory as OSFactory,
};
use Innmind\Server\Control\{
    Server\Command,
    Servers\Mock\ProcessBuilder,
};
use Innmind\TimeContinuum\PointInTime;
use Innmind\Immutable\Map;

final class Factory
{
    /**
     * @psalm-mutation-free
     *
     * @param Map<non-empty-string, callable(Command, ProcessBuilder, OperatingSystem): ProcessBuilder> $executables
     */
    private function __construct(
        private ?PointInTime $start,
        private Map $executables,
    ) {
    }

    /**
     * @psalm-pure
     */
    public static function new(): self
    {
        return new self(
            null,
            Map::of(),
        );
    }

    /**
     * @psalm-mutation-free
     *
     * @param non-empty-string $date
     */
    #[\NoDiscard]
    public function startClockAt(string $date): self
    {
        return new self(
            PointInTime::at(new \DateTimeImmutable($date)),
            $this->executables,
        );
    }

    /**
     * @psalm-mutation-free
     *
     * @param non-empty-string $bin
     * @param callable(Command, ProcessBuilder, OperatingSystem): ProcessBuilder $builder
     */
    #[\NoDiscard]
    public function handleExecutable(
        string $bin,
        callable $builder,
    ): self {
        return new self(
            $this->start,
            ($this->executables)(
                $bin,
                $builder,
            ),
        );
    }

    public function build(): OperatingSystem
    {
        $os = OSFactory::build();
        $config = new Config(
            $this->start,
            $this->executables->map(static function($_, $build) use (&$os) {
                return static function(Command $command, ProcessBuilder $builder) use ($build, &$os) {
                    return $build($command, $builder, $os);
                };
            }),
        );
        // The new $os is not directly returned in order for callables to have
        // the newly built OS injected at runtime.
        $os = $os->map($config);

        return $os;
    }
}
