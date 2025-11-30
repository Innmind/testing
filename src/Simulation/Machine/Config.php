<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation\Machine;

use Innmind\Testing\Simulation\Network;
use Innmind\OperatingSystem\Config as OSConfig;
use Innmind\Server\Control\Server;
use Innmind\TimeWarp\Halt;
use Innmind\TimeContinuum\Clock;
use Innmind\Immutable\Attempt;

/**
 * @internal
 */
final class Config
{
    public static function of(
        Network $network,
        Processes $processes,
    ): OSConfig {
        return OSConfig::new()
            ->withClock(Clock::via(static fn() => $network->ntp()->now()))
            ->haltProcessVia(Halt::via(static fn($period) => Attempt::result(
                $network->ntp()->advance($period),
            )))
            ->useServerControl(Server::via(
                static fn($command) => $processes->run($command),
            ));
    }
}
