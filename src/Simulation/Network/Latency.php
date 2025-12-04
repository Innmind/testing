<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation\Network;

use Innmind\Testing\Simulation\NTPServer;
use Innmind\TimeContinuum\Period;
use Innmind\Mutable\Ring;

final class Latency
{
    /**
     * @psalm-mutation-free
     *
     * @param Ring<int<0, max>> $latencies
     */
    private function __construct(
        private Ring $latencies,
    ) {
    }

    public function __invoke(NTPServer $ntp): void
    {
        $this
            ->latencies
            ->pull()
            ->map(Period::millisecond(...))
            ->match(
                $ntp->advance(...),
                static fn() => null,
            );
    }

    /**
     * @psalm-pure
     *
     * @param list<int<0, max>> $latencies
     */
    public static function of(array $latencies): self
    {
        return new self(Ring::of(...$latencies));
    }
}
