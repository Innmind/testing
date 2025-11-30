<?php
declare(strict_types = 1);

namespace Innmind\Testing;

use Innmind\Testing\Machine\ProcessBuilder;
use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Server\Control\Server\Command;
use Innmind\Http\{
    ServerRequest,
    Response,
};
use Innmind\Immutable\{
    Attempt,
    Map,
};

final class Machine
{
    /**
     * @psalm-mutation-free
     *
     * @param non-empty-list<string> $domains
     * @param Map<non-empty-string, callable(Command, ProcessBuilder, OperatingSystem): ProcessBuilder> $executables
     * @param Map<?int<1, max>, callable(ServerRequest, OperatingSystem): Attempt<Response>> $http
     */
    private function __construct(
        private array $domains,
        private Map $executables,
        private Map $http,
        private Machine\Clock\Drift $drift,
    ) {
    }

    /**
     * @psalm-pure
     * @no-named-arguments
     */
    #[\NoDiscard]
    public static function new(
        string $domain,
        string ...$domains,
    ): self {
        return new self(
            [$domain, ...$domains],
            Map::of(),
            Map::of(),
            Machine\Clock\Drift::of(),
        );
    }

    /**
     * @psalm-mutation-free
     *
     * @param non-empty-string $executable
     * @param callable(Command, ProcessBuilder, OperatingSystem): ProcessBuilder $builder
     */
    #[\NoDiscard]
    public function install(
        string $executable,
        callable $builder,
    ): self {
        return new self(
            $this->domains,
            ($this->executables)($executable, $builder),
            $this->http,
            $this->drift,
        );
    }

    /**
     * @psalm-mutation-free
     *
     * @param callable(ServerRequest, OperatingSystem): Attempt<Response> $handle
     * @param ?int<1, max> $port
     */
    #[\NoDiscard]
    public function listenHttp(
        callable $handle,
        ?int $port = null,
    ): self {
        return new self(
            $this->domains,
            $this->executables,
            ($this->http)($port, $handle),
            $this->drift,
        );
    }

    /**
     * @psalm-mutation-free
     */
    #[\NoDiscard]
    public function driftClockBy(Machine\Clock\Drift $drift): self
    {
        return new self(
            $this->domains,
            $this->executables,
            $this->http,
            $drift,
        );
    }

    // todo add environment variables
    // todo add clock drift
    // todo map($this): self
    // todo add crontab ?

    public function boot(Simulation\Network $network): void
    {
        $network->with(
            $this->domains,
            fn() => Simulation\Machine::new(
                $network,
                $this->executables,
                $this->http,
                $this->drift,
            ),
        );
    }
}
