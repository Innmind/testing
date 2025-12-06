<?php
declare(strict_types = 1);

namespace Innmind\Testing;

use Innmind\TimeContinuum\PointInTime;
use Innmind\Immutable\Set;

final class Cluster
{
    /**
     * @psalm-mutation-free
     *
     * @param Set<Machine> $machines
     * @param ?int<2, 10> $clockSpeed
     */
    private function __construct(
        private ?PointInTime $start,
        private Set $machines,
        private Network\Latency $latency,
        private ?int $clockSpeed,
    ) {
    }

    /**
     * @psalm-pure
     */
    #[\NoDiscard]
    public static function new(): self
    {
        return new self(
            null,
            Set::of(),
            Network\Latency::of(),
            null,
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
            $this->machines,
            $this->latency,
            $this->clockSpeed,
        );
    }

    /**
     * @psalm-mutation-free
     *
     * @param int<2, 10> $speed
     */
    #[\NoDiscard]
    public function speedUpTimeBy(int $speed): self
    {
        return new self(
            $this->start,
            $this->machines,
            $this->latency,
            $speed,
        );
    }

    /**
     * @psalm-mutation-free
     */
    #[\NoDiscard]
    public function add(Machine $machine): self
    {
        return new self(
            $this->start,
            ($this->machines)($machine),
            $this->latency,
            $this->clockSpeed,
        );
    }

    /**
     * @psalm-mutation-free
     */
    #[\NoDiscard]
    public function withNetworkLatency(Network\Latency $latency): self
    {
        return new self(
            $this->start,
            $this->machines,
            $latency,
            $this->clockSpeed,
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

    /**
     * @internal
     */
    #[\NoDiscard]
    public function boot(): Simulation\Cluster
    {
        $ntp = Simulation\NTPServer::new(
            $this->start,
            $this->clockSpeed,
        );
        $network = Simulation\Network::new($ntp, $this->latency);
        $_ = $this->machines->foreach(
            static fn($machine) => $machine->boot($network),
        );

        return Simulation\Cluster::new($network);
    }
}
