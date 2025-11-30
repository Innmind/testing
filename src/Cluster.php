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
    ) {
    }

    /**
     * @psalm-pure
     */
    #[\NoDiscard]
    public static function new(): self
    {
        return new self(null, Set::of());
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
        );
    }

    #[\NoDiscard]
    public function boot(): Simulation\Cluster
    {
        $ntp = Simulation\NTPServer::new($this->start);
        $network = Simulation\Network::new($ntp);
        $_ = $this->machines->foreach(
            static fn($machine) => $machine->boot($network),
        );

        return Simulation\Cluster::new($network);
    }
}
