<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation;

use Innmind\Testing\{
    Network\Latency,
    Exception\CouldNotResolveHost,
};
use Innmind\Http\{
    Request,
    Response,
};
use Innmind\Immutable\{
    Map,
    Attempt,
};

/**
 * @internal
 */
final class Network
{
    /**
     * @param Map<string, Machine> $machines
     */
    private function __construct(
        private Map $machines,
        private NTPServer $ntp,
        private Network\Latency $latency,
    ) {
    }

    /**
     * @internal
     */
    #[\NoDiscard]
    public static function new(NTPServer $ntp, Latency $latency): self
    {
        return new self(
            Map::of(),
            $ntp,
            $latency->asState(),
        );
    }

    /**
     * @param non-empty-list<string> $domains
     */
    public function with(array $domains, Machine $machine): void
    {
        foreach ($domains as $domain) {
            $this->machines = ($this->machines)(
                $domain,
                $machine,
            );
        }
    }

    #[\NoDiscard]
    public function ntp(): NTPServer
    {
        return $this->ntp;
    }

    /**
     * @return Attempt<Response>
     */
    #[\NoDiscard]
    public function http(Request $request): Attempt
    {
        ($this->latency)($this->ntp);
        $host = $request->url()->authority()->host()->toString();

        return $this
            ->machines
            ->get($host)
            ->attempt(static fn() => new CouldNotResolveHost($host))
            ->flatMap(static fn($machine) => $machine->http($request))
            ->map(function($response) {
                ($this->latency)($this->ntp);

                return $response;
            })
            ->mapError(function($error) {
                ($this->latency)($this->ntp);

                return $error;
            });
    }

    /**
     * @return Attempt<Machine>
     */
    #[\NoDiscard]
    public function ssh(string $host): Attempt
    {
        return $this
            ->machines
            ->get($host)
            ->attempt(static fn() => new CouldNotResolveHost($host));
    }
}
