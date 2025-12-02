<?php
declare(strict_types = 1);

namespace Innmind\Testing;

use Innmind\Testing\Machine\ProcessBuilder;
use Innmind\OperatingSystem\{
    OperatingSystem,
    Config,
};
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
     * @param Map<non-empty-string, callable(Command, ProcessBuilder, OperatingSystem, Map<string, string>): ProcessBuilder> $executables
     * @param Map<?int<1, max>, callable(ServerRequest, OperatingSystem, Map<string, string>): Attempt<Response>> $http
     * @param Map<string, string> $environment
     * @param \Closure(Config): Config $configureOS
     */
    private function __construct(
        private array $domains,
        private Map $executables,
        private Map $http,
        private Map $environment,
        private Machine\Clock\Drift $drift,
        private \Closure $configureOS,
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
            Map::of(),
            Machine\Clock\Drift::of(),
            static fn(Config $config) => $config,
        );
    }

    /**
     * @psalm-mutation-free
     *
     * @param non-empty-string $executable
     * @param callable(Command, ProcessBuilder, OperatingSystem, Map<string, string>): ProcessBuilder $builder
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
            $this->environment,
            $this->drift,
            $this->configureOS,
        );
    }

    /**
     * @psalm-mutation-free
     *
     * @param callable(ServerRequest, OperatingSystem, Map<string, string>): Attempt<Response> $handle
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
            $this->environment,
            $this->drift,
            $this->configureOS,
        );
    }

    /**
     * @psalm-mutation-free
     */
    #[\NoDiscard]
    public function withEnvironment(string $key, string $value): self
    {
        return new self(
            $this->domains,
            $this->executables,
            $this->http,
            ($this->environment)($key, $value),
            $this->drift,
            $this->configureOS,
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
            $this->environment,
            $drift,
            $this->configureOS,
        );
    }

    /**
     * @psalm-mutation-free
     *
     * @param callable(Config): Config $map
     */
    #[\NoDiscard]
    public function configureOperatingSystem(callable $map): self
    {
        return new self(
            $this->domains,
            $this->executables,
            $this->http,
            $this->environment,
            $this->drift,
            \Closure::fromCallable($map),
        );
    }

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
                $this->environment,
                $this->drift,
                $this->configureOS,
            ),
        );
    }
}
