<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation;

use Innmind\Testing\Machine\{
    ProcessBuilder,
    Clock,
};
use Innmind\OperatingSystem\{
    OperatingSystem,
    Config,
};
use Innmind\Server\Control\Server\Command;
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

final class Machine
{
    /**
     * @param Map<?int, callable(ServerRequest, OperatingSystem, Map<string, string>): Attempt<Response>> $http
     * @param Map<string, string> $environment
     */
    private function __construct(
        private Machine\OS $os,
        private Machine\Processes $processes,
        private Map $http,
        private Map $environment,
    ) {
    }

    /**
     * @param Map<non-empty-string, callable(Command, ProcessBuilder, OperatingSystem, Map<string, string>): ProcessBuilder> $executables
     * @param Map<?int<1, max>, callable(ServerRequest, OperatingSystem, Map<string, string>): Attempt<Response>> $http
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
            $processes,
            $http,
            $environment,
        );
    }

    /**
     * @return Attempt<Response>
     */
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
            ->attempt(static fn() => new \RuntimeException('Connection timeout')) // todo inject fake timeout in ntp server ?
            ->flatMap(fn($http) => $http(
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

    // todo allow ssh
}
