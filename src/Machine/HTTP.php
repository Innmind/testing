<?php
declare(strict_types = 1);

namespace Innmind\Testing\Machine;

use Innmind\OperatingSystem\OperatingSystem;
use Innmind\Http\{
    ServerRequest,
    Response,
};
use Innmind\Immutable\{
    Attempt,
    Map,
};

final class HTTP
{
    /**
     * @psalm-mutation-free
     *
     * @param \Closure(ServerRequest, OperatingSystem, Map<string, string>): Attempt<Response> $app
     */
    private function __construct(
        private \Closure $app, // todo support innmind/framework
    ) {
    }

    /**
     * @param Map<string, string> $environment
     *
     * @return Attempt<Response>
     */
    public function __invoke(
        ServerRequest $request,
        OperatingSystem $os,
        Map $environment,
    ): Attempt {
        return ($this->app)($request, $os, $environment);
    }

    /**
     * @psalm-pure
     *
     * @param callable(ServerRequest, OperatingSystem, Map<string, string>): Attempt<Response> $app
     */
    #[\NoDiscard]
    public static function of(callable $app): self
    {
        return new self(\Closure::fromCallable($app));
    }
}
