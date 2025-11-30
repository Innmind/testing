<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation;

use Innmind\Testing\Machine\ProcessBuilder;
use Innmind\OperatingSystem\OperatingSystem;
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
     * @param Map<non-empty-string, callable(Command, ProcessBuilder, OperatingSystem): ProcessBuilder> $executables
     * @param Map<?int, callable(ServerRequest, OperatingSystem): Attempt<Response>> $http
     */
    private function __construct(
        private OperatingSystem $os,
        private Map $executables,
        private Map $http,
        // private Map<string, string> $environment, todo
    ) {
    }

    /**
     * @param Map<non-empty-string, callable(Command, ProcessBuilder, OperatingSystem): ProcessBuilder> $executables
     * @param Map<?int<1, max>, callable(ServerRequest, OperatingSystem): Attempt<Response>> $http
     */
    #[\NoDiscard]
    public static function new(
        Network $network,
        Map $executables,
        Map $http,
    ): self {
        return new self(
            OperatingSystem::new(Machine\Config::of($network)),
            $executables,
            $http,
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

        $serverRequest = ServerRequest::of(
            $request->url(),
            $request->method(),
            $request->protocolVersion(),
            $request->headers(),
            // Simulate network by preventing iterating over the initial body
            // twice. Though this approach prevents streaming, use
            // `Sequence::defer()` instead ?
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
            ->flatMap(fn($http) => $http($serverRequest, $this->os));
    }

    // todo allow ssh
}
