<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation\Network;

use Innmind\Testing\Simulation\NTPServer;
use Innmind\TimeContinuum\Period;

final class Latency
{
    /**
     * @psalm-mutation-free
     *
     * @param list<int<0, max>> $latencies
     */
    private function __construct(
        private array $latencies,
        private ?int $accumulated = null,
    ) {
    }

    public function __invoke(NTPServer $ntp): void
    {
        if (\count($this->latencies) === 0) {
            return;
        }

        /** @var int<0, max>|false */
        $latency = \current($this->latencies);

        if (!\is_int($latency)) {
            $latency = \reset($this->latencies);
        }

        \next($this->latencies);

        $ntp->advance(Period::millisecond($latency));
    }

    /**
     * @psalm-pure
     *
     * @param list<int<0, max>> $latencies
     */
    public static function of(array $latencies): self
    {
        return new self($latencies);
    }
}
