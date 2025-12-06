<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation;

use Innmind\Testing\{
    Machine\Clock,
    Machine\CLI,
    Machine\HTTP,
    Exception\ConnectionTimeout,
};
use Innmind\OperatingSystem\{
    OperatingSystem,
    Config,
};
use Innmind\Server\Control\Server\{
    Command,
    Process,
};
use Innmind\Http\{
    ServerRequest,
    Request,
    Response,
};
use Innmind\Filesystem\File\Content;
use Innmind\Url\Authority\Port;
use Innmind\Immutable\{
    Map,
    Attempt,
};

/**
 * @internal
 */
final class Machine
{
    /**
     * @param Map<?int, HTTP> $http
     * @param Map<string, string> $environment
     */
    private function __construct(
        private Machine\OS $os,
        private Network $network,
        private Machine\Processes $processes,
        private Machine\Clock\Drift $drift,
        private Map $http,
        private Map $environment,
    ) {
    }

    /**
     * @internal
     *
     * @param Map<non-empty-string, CLI> $executables
     * @param Map<?int<1, max>, HTTP> $http
     * @param Map<string, string> $environment
     * @param \Closure(Config): Config $configureOS
     */
    #[\NoDiscard]
    public static function new(
        Network $network,
        Map $executables,
        Map $http,
        Map $environment,
        Clock\Drift $drift,
        \Closure $configureOS,
    ): self {
        $drift = $drift->asState();

        $os = Machine\OS::new();
        $processes = Machine\Processes::new(
            $os,
            $executables,
            $environment,
        );
        $os->boot(OperatingSystem::new($configureOS(
            Machine\Config::of(
                $network,
                $processes,
                $drift,
            ),
        )));

        return new self(
            $os,
            $network,
            $processes,
            $drift,
            $http,
            $environment,
        );
    }

    /**
     * @return Attempt<Response>
     */
    #[\NoDiscard]
    public function http(Request $request): Attempt
    {
        $port = $request->url()->authority()->port();

        $value = match ($port->equals(Port::none())) {
            true => null,
            false => $port->value(),
        };

        // Simulate network by preventing iterating over the request/response
        // bodies twice. Though this approach prevents streaming, todo use
        // `Sequence::defer()` instead ?

        $serverRequest = ServerRequest::of(
            $request->url(),
            $request->method(),
            $request->protocolVersion(),
            $request->headers(),
            Content::ofChunks(
                $request
                    ->body()
                    ->chunks()
                    ->snap(),
            ),
            // todo parse the content
        );

        return $this
            ->http
            ->get($value)
            ->attempt(static fn() => new ConnectionTimeout) // todo inject fake timeout in ntp server ?
            ->flatMap(fn($app) => $app(
                $serverRequest,
                $this->os->unwrap(),
                $this->environment,
            ))
            ->map(static fn($response) => Response::of(
                $response->statusCode(),
                $response->protocolVersion(),
                $response->headers(),
                Content::ofChunks(
                    $response
                        ->body()
                        ->chunks()
                        ->snap(),
                ),
            ));
    }

    /**
     * @return Attempt<Process>
     */
    #[\NoDiscard]
    public function run(Command|Command\OverSsh $command): Attempt
    {
        if ($command instanceof Command\OverSsh) {
            return $this
                ->drift
                ->reset($this->network)
                ->ssh($command->host()->toString())
                ->flatMap(static fn($machine) => $machine->run($command->command()));
        }

        return $this->processes->run($command);
    }
}
