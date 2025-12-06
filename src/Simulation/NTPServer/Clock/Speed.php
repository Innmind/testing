<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation\NTPServer\Clock;

use Innmind\TimeContinuum\PointInTime;

/**
 * @internal
 */
final class Speed
{
    /**
     * @psalm-mutation-free
     *
     * @param ?int<2, 10> $multiplier
     */
    private function __construct(
        private PointInTime $previous,
        private ?int $multiplier,
    ) {
    }

    #[\NoDiscard]
    public function __invoke(PointInTime $now): PointInTime
    {
        if (\is_null($this->multiplier)) {
            return $now;
        }

        $elapsed = $now->elapsedSince($this->previous)->asPeriod();
        $this->previous = $now;

        for ($i = 1; $i < $this->multiplier; $i++) {
            $now = $now->goForward($elapsed);
        }

        return $now;
    }

    /**
     * @psalm-pure
     * @internal
     *
     * @param ?int<2, 10> $multiplier
     */
    #[\NoDiscard]
    public static function of(PointInTime $previous, ?int $multiplier): self
    {
        return new self($previous, $multiplier);
    }
}
