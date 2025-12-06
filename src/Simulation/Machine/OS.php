<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation\Machine;

use Innmind\OperatingSystem\OperatingSystem;

/**
 * @internal
 */
final class OS
{
    /**
     * @psalm-mutation-free
     */
    private function __construct(
        private ?OperatingSystem $os,
    ) {
    }

    /**
     * @psalm-pure
     * @internal
     */
    #[\NoDiscard]
    public static function new(): self
    {
        return new self(null);
    }

    public function boot(OperatingSystem $os): void
    {
        $this->os = $os;
    }

    #[\NoDiscard]
    public function unwrap(): OperatingSystem
    {
        if (\is_null($this->os)) {
            throw new \LogicException('Machine OS should be booted');
        }

        return $this->os;
    }
}
