<?php
declare(strict_types = 1);

namespace Innmind\Testing\Network;

use Innmind\Testing\Simulation\Network;

/**
 * @psalm-immutable
 */
final class Latency
{
    /**
     * @param list<int<0, max>> $latencies
     */
    private function __construct(
        private array $latencies,
    ) {
    }

    /**
     * The latencies are expressed in milliseconds
     *
     * @psalm-pure
     * @no-named-arguments
     *
     * @param int<0, max> ...$latencies
     */
    #[\NoDiscard]
    public static function of(int ...$latencies): self
    {
        return new self($latencies);
    }

    /**
     * @internal
     */
    #[\NoDiscard]
    public function asState(): Network\Latency
    {
        return Network\Latency::of($this->latencies);
    }
}
