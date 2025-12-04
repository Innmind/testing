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
     */
    private function __construct(
        private ?PointInTime $start,
        private Set $machines,
        private Network\Latency $latency,
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

    #[\NoDiscard]
    public function boot(): Simulation\Cluster
    {
        $ntp = Simulation\NTPServer::new($this->start);
        $network = Simulation\Network::new($ntp, $this->latency);
        $_ = $this->machines->foreach(
            static fn($machine) => $machine->boot($network),
        );

        return Simulation\Cluster::new($network);
    }
}
