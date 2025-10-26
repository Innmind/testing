<?php
declare(strict_types = 1);

namespace Innmind\Testing;

use Innmind\OperatingSystem\{
    OperatingSystem,
    Config as OSConfig,
};
use Innmind\Server\Control\{
    Server\Command,
    Servers\Mock\ProcessBuilder,
};
use Innmind\TimeContinuum\PointInTime;
use Innmind\Immutable\Map;

/**
 * @internal
 */
final class Config
{
    /**
     * @param Map<non-empty-string, callable(Command, ProcessBuilder, OperatingSystem): ProcessBuilder> $executables
     */
    public function __construct(
        private ?PointInTime $start,
        private Map $executables,
    ) {
    }

    public function __invoke(OSConfig $config): OSConfig
    {
        return $config;
    }
}
