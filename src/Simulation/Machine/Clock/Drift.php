<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation\Machine\Clock;

use Innmind\Testing\Simulation\Network;
use Innmind\TimeContinuum\{
    PointInTime,
    Period,
};
use Innmind\Mutable\Ring;

/**
 * @internal
 */
final class Drift
{
    /**
     * @psalm-mutation-free
     *
     * @param Ring<int> $drifts
     */
    private function __construct(
        private Ring $drifts,
        private int $accumulated = 0,
    ) {
    }

    #[\NoDiscard]
    public function __invoke(PointInTime $now): PointInTime
    {
        return $this
            ->drifts
            ->pull()
            ->map(fn($drift) => $this->accumulated += $drift)
            ->match(
                static fn($period) => match (true) {
                    $period === 0 => $now,
                    $period > 0 => $now->goForward(Period::millisecond($period)),
                    $period < 0 => $now->goBack(Period::millisecond($period * -1)),
                },
                static fn() => $now,
            );
    }

    /**
     * @psalm-pure
     * @internal
     *
     * @param list<int> $drifts
     */
    public static function of(array $drifts): self
    {
        return new self(Ring::of(...$drifts));
    }

    /**
     * Drift reset can only occur when accessing the network to simulate the
     * machine is synchronizing with the NTP server.
     */
    public function reset(Network $value): Network
    {
        $this->accumulated = 0;
        $this->drifts->reset();

        return $value;
    }
}
