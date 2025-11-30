<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation;

use Innmind\Testing\Exception\CouldNotResolveHost;
use Innmind\Http\{
    Request,
    Response,
};
use Innmind\Immutable\{
    Map,
    Attempt,
};

final class Network
{
    /**
     * @param Map<string, Machine> $machines
     */
    private function __construct(
        private Map $machines,
        private NTPServer $ntp,
    ) {
    }

    public static function new(NTPServer $ntp): self
    {
        return new self(
            Map::of(),
            $ntp,
        );
    }

    /**
     * @param non-empty-list<string> $domains
     * @param callable(): Machine $boot
     */
    public function with(array $domains, callable $boot): void
    {
        $machine = $boot();

        foreach ($domains as $domain) {
            $this->machines = ($this->machines)(
                $domain,
                $machine,
            );
        }
    }

    public function ntp(): NTPServer
    {
        return $this->ntp;
    }

    /**
     * @return Attempt<Response>
     */
    public function http(Request $request): Attempt
    {
        $host = $request->url()->authority()->host()->toString();

        return $this
            ->machines
            ->get($host)
            ->attempt(static fn() => new CouldNotResolveHost($host))
            ->flatMap(static fn($machine) => $machine->http($request));
    }
}
