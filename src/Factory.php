<?php
declare(strict_types = 1);

namespace Innmind\Testing;

use Innmind\Testing\Machine\ProcessBuilder;
use Innmind\OperatingSystem\{
    OperatingSystem,
    Factory as OSFactory,
};
use Innmind\Server\Control\Server\Command;
use Innmind\Http\{
    ServerRequest,
    Response,
};
use Innmind\Url\Url;
use Innmind\TimeContinuum\PointInTime;
use Innmind\Immutable\{
    Map,
    Attempt,
};

final class Factory
{
    /**
     * @psalm-mutation-free
     *
     * @param Map<non-empty-string, callable(Command, ProcessBuilder, OperatingSystem): ProcessBuilder> $executables
     * @param Map<string, callable(ServerRequest, OperatingSystem): Attempt<Response>> $httpDomains
     */
    private function __construct(
        private ?PointInTime $start,
        private Map $executables,
        private Map $httpDomains,
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
            Map::of(),
        );
    }

    /**
     * @psalm-mutation-free
     */
    #[\NoDiscard]
    public function startClockAt(PointInTime $date): self
    {
        return new self(
            $date,
            $this->executables,
            $this->httpDomains,
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
            $this->httpDomains,
        );
    }

    /**
     * @psalm-mutation-free
     *
     * @param callable(ServerRequest, OperatingSystem): Attempt<Response> $handle
     */
    #[\NoDiscard]
    public function handleHttpDomain(
        Url $domain,
        callable $handle,
    ): self {
        return new self(
            $this->start,
            $this->executables,
            ($this->httpDomains)(
                $domain->toString(),
                $handle,
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
            $this->httpDomains,
        );
        // The new $os is not directly returned in order for callables to have
        // the newly built OS injected at runtime.
        $os = $os->map($config);

        return $os;
    }
}
