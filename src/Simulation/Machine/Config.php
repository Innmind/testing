<?php
declare(strict_types = 1);

namespace Innmind\Testing\Simulation\Machine;

use Innmind\Testing\{
    Simulation\Network,
    Simulation\Machine\Clock\Drift,
    Exception\CouldNotResolveHost,
};
use Innmind\OperatingSystem\Config as OSConfig;
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
use Innmind\TimeWarp\Halt;
use Innmind\Http\{
    Response,
    Response\StatusCode,
};
use Innmind\TimeContinuum\Clock;
use Innmind\Immutable\{
    Attempt,
    Either,
};

/**
 * @internal
 */
final class Config
{
    public static function of(
        Network $network,
        Processes $processes,
        Drift $drift,
    ): OSConfig {
        return OSConfig::new()
            ->withClock(Clock::via(static fn() => $drift(
                $network->ntp()->now(),
            )))
            ->haltProcessVia(Halt::via(static fn($period) => Attempt::result(
                $network->ntp()->advance($period),
            )))
            ->useServerControl(Server::via(
                static fn($command) => match (true) {
                    $command instanceof Command => $processes->run($command),
                    default => $drift
                        ->reset($network)
                        ->ssh($command->host()->toString())
                        ->flatMap(static fn($machine) => $machine->run($command->command())),
                },
            ))
            ->useHttpTransport(Transport::via(
                static fn($request) => $drift
                    ->reset($network)
                    ->http($request)
                    ->match(
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
                        static fn($e) => Either::left(match (true) {
                            $e instanceof CouldNotResolveHost => new ConnectionFailed(
                                $request,
                                $e->getMessage(),
                            ),
                            default => new ServerError(
                                $request,
                                Response::of(
                                    StatusCode::internalServerError,
                                    $request->protocolVersion(),
                                ),
                            ),
                        }),
                    ),
            ));
    }
}
