<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation\Machine;

use Innmind\OperatingSystem\OperatingSystem;

final class OS
{
    private function __construct(
        private ?OperatingSystem $os,
    ) {
    }

    public static function new(): self
    {
        return new self(null);
    }

    public function boot(OperatingSystem $os): void
    {
        $this->os = $os;
    }

    public function unwrap(): OperatingSystem
    {
        if (\is_null($this->os)) {
            throw new \LogicException('Machine OS should be booted');
        }

        return $this->os;
    }
}
