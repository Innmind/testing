<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation\Machine\Clock;

use Innmind\Testing\Simulation\Network;
use Innmind\TimeContinuum\{
    PointInTime,
    Period,
};

final class Drift
{
    /**
     * @psalm-mutation-free
     *
     * @param list<int> $drifts
     */
    private function __construct(
        private array $drifts,
        private ?int $accumulated = null,
    ) {
    }

    public function __invoke(PointInTime $now): PointInTime
    {
        if (\count($this->drifts) === 0) {
            return $now;
        }

        /** @var int|false */
        $drift = \current($this->drifts);

        if (!\is_int($drift)) {
            $drift = \reset($this->drifts);
        }

        \next($this->drifts);

        $this->accumulated = match ($this->accumulated) {
            null => $drift,
            default => $this->accumulated + $drift,
        };

        if ($this->accumulated === 0) {
            return $now;
        }

        if ($this->accumulated > 0) {
            return $now->goForward(Period::millisecond($this->accumulated));
        }

        return $now->goBack(Period::millisecond($this->accumulated * -1));
    }

    /**
     * @psalm-pure
     *
     * @param list<int> $drifts
     */
    public static function of(array $drifts): self
    {
        return new self($drifts);
    }

    /**
     * Drift reset can only occur when accessing the network to simulate the
     * machine is synchronizing with the NTP server.
     */
    public function reset(Network $value): Network
    {
        $this->accumulated = null;
        \reset($this->drifts);

        return $value;
    }
}
