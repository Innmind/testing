<?php
declare(strict_types = 1);

namespace Innmind\Testing;

use Innmind\Testing\Machine\{
    ProcessBuilder,
    State\Clock as SimulatedClock,
};
use Innmind\OperatingSystem\{
    OperatingSystem,
    Config as OSConfig,
};
use Innmind\Server\Control\{
    Server,
    Server\Command,
};
use Innmind\HttpTransport\{
    Transport,
    Information,
    Success,
    Redirection,
    ClientError,
    ServerError,
    ConnectionFailed,
};
use Innmind\Http\{
    ServerRequest,
    Response,
    Response\StatusCode,
};
use Innmind\Filesystem\File\Content;
use Innmind\TimeWarp\Halt;
use Innmind\TimeContinuum\{
    Clock,
    PointInTime,
};
use Innmind\Immutable\{
    Map,
    Attempt,
    Either,
};

/**
 * @internal
 */
final class Config
{
    /**
     * @param Map<non-empty-string, callable(Command, ProcessBuilder): ProcessBuilder> $executables
     * @param Map<string, callable(ServerRequest, OperatingSystem): Attempt<Response>> $httpDomains
     */
    public function __construct(
        private ?PointInTime $start,
        private Map $executables,
        private Map $httpDomains,
    ) {
    }

    public function __invoke(OSConfig $config): OSConfig
    {
        $processes = 2;
        $clock = $config->clock();
        $simulatedClock = SimulatedClock::of(
            $clock,
            $this->start ?? $clock->now(),
        );
        $executables = $this->executables;
        $httpDomains = $this->httpDomains;

        return $config
            ->withClock(Clock::via(static fn() => $simulatedClock->now()))
            ->haltProcessVia(Halt::via(static fn($period) => Attempt::result(
                $simulatedClock->halt($period),
            )))
            ->useServerControl(Server::via(
                static function($command) use ($executables, &$processes) {
                    // todo build proper api in package
                    /**
                     * @psalm-suppress PossiblyNullFunctionCall
                     * @psalm-suppress UndefinedThisPropertyFetch
                     * @psalm-suppress MixedReturnStatement
                     * @var non-empty-string
                     */
                    $executable = (\Closure::bind(
                        fn(): string => $this->executable,
                        $command,
                        Command::class,
                    ))();
                    ++$processes;

                    return $executables
                        ->get($executable)
                        ->match(
                            static fn($build) => Attempt::result($build($command, ProcessBuilder::new($processes)))
                                ->map(static fn($builder) => $builder->build()),
                            static fn() => Attempt::error(new \RuntimeException( // todo return a failed process instead ?
                                \sprintf(
                                    'Failed to start %s command',
                                    $executable,
                                ),
                            )),
                        );
                },
            ))
            ->useHttpTransport(Transport::via(
                static function($request) use ($httpDomains) {
                    $serverRequest = ServerRequest::of(
                        $request->url(),
                        $request->method(),
                        $request->protocolVersion(),
                        $request->headers(),
                        Content::ofChunks(
                            $request
                                ->body()
                                ->chunks()
                                ->snap(), // simulate network
                        ),
                        // todo parse the content
                    );
                    $domain = $request
                        ->url()
                        ->withAuthority(
                            $request
                                ->url()
                                ->authority()
                                ->withoutUserInformation(),
                        )
                        ->withoutPath()
                        ->withoutQuery()
                        ->withoutFragment()
                        ->toString();

                    return $httpDomains
                        ->get($domain)
                        ->match(
                            static fn($handle) => $handle(
                                $serverRequest,
                                Factory::new()->build(), // todo reuse OS between same domains
                            )->match(
                                static fn($response) => match ($response->statusCode()->range()) {
                                    StatusCode\Range::informational => Either::left(new Information(
                                        $request,
                                        $response,
                                    )),
                                    StatusCode\Range::successful => Either::right(new Success(
                                        $request,
                                        $response,
                                    )),
                                    StatusCode\Range::redirection => Either::left(new Redirection(
                                        $request,
                                        $response,
                                    )),
                                    StatusCode\Range::clientError => Either::left(new ClientError(
                                        $request,
                                        $response,
                                    )),
                                    StatusCode\Range::serverError => Either::left(new ServerError(
                                        $request,
                                        $response,
                                    )),
                                },
                                static fn() => Either::left(new ServerError(
                                    $request,
                                    Response::of(
                                        StatusCode::internalServerError,
                                        $request->protocolVersion(),
                                    ),
                                )),
                            ),
                            static fn() => Either::left(new ConnectionFailed(
                                $request,
                                \sprintf('Unable to connect to %s', $domain),
                            )),
                        );
                },
            ));
    }
}
