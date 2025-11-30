<?php
declare(strict_types = 1);

namespace Innmind\Testing\Machine\Clock;

use Innmind\Testing\Simulation\Machine\Clock;

/**
 * @psalm-immutable
 */
final class Drift
{
    /**
     * @param list<int> $drifts
     */
    private function __construct(
        private array $drifts,
    ) {
    }

    /**
     * The drifts are expressed in milliseconds
     *
     * @psalm-pure
     * @no-named-arguments
     */
    public static function of(int ...$drifts): self
    {
        return new self($drifts);
    }

    public function asState(): Clock\Drift
    {
        return Clock\Drift::of($this->drifts);
    }
}
