<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation;

use Innmind\Server\Control\Server\{
    Command,
    Process,
};
use Innmind\Http\{
    Request,
    Response,
};
use Innmind\Immutable\Attempt;

final class Cluster
{
    private function __construct(
        private Network $network,
    ) {
    }

    public static function new(Network $network): self
    {
        return new self($network);
    }

    /**
     * @return Attempt<Response>
     */
    public function http(Request $request): Attempt
    {
        return $this->network->http($request);
    }

    /**
     * @return Attempt<callable(Command): Attempt<Process>>
     */
    public function ssh(string $host): Attempt
    {
        return $this
            ->network
            ->ssh($host)
            ->map(static fn($machine) => static fn(Command $command) => $machine->run($command));
    }
}
