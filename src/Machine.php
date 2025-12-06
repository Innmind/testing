<?php
declare(strict_types = 1);

namespace Innmind\Testing;

use Innmind\OperatingSystem\Config;
use Innmind\Immutable\Map;

final class Machine
{
    /**
     * @psalm-mutation-free
     *
     * @param non-empty-list<string> $domains
     * @param Map<non-empty-string, Machine\CLI> $executables
     * @param Map<?int<1, max>, Machine\HTTP> $http
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
     */
    #[\NoDiscard]
    public function install(
        string $executable,
        Machine\CLI $app,
    ): self {
        return new self(
            $this->domains,
            ($this->executables)($executable, $app),
            $this->http,
            $this->environment,
            $this->drift,
            $this->configureOS,
        );
    }

    /**
     * @psalm-mutation-free
     *
     * @param ?int<1, max> $port
     */
    #[\NoDiscard]
    public function listen(
        Machine\HTTP $app,
        ?int $port = null,
    ): self {
        return new self(
            $this->domains,
            $this->executables,
            ($this->http)($port, $app),
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

    /**
     * @psalm-mutation-free
     *
     * @param callable(self): self $map
     */
    #[\NoDiscard]
    public function map(callable $map): self
    {
        /** @psalm-suppress ImpureFunctionCall */
        return $map($this);
    }

    // todo add crontab ?

    /**
     * @internal
     */
    public function boot(Simulation\Network $network): void
    {
        $network->with(
            $this->domains,
            Simulation\Machine::new(
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
