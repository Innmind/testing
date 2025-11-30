<?php
declare(strict_types = 1);

use Innmind\Testing\{
    Machine,
    Cluster,
};
use Innmind\Server\Control\Server\Command;
use Innmind\HttpTransport\ConnectionFailed;
use Innmind\Http\{
    Request,
    Response,
    Response\StatusCode,
    Method,
    ProtocolVersion,
};
use Innmind\Filesystem\File\Content;
use Innmind\Url\Url;
use Innmind\TimeContinuum\{
    Period,
    Format,
    Offset,
};
use Innmind\Immutable\{
    Attempt,
    Sequence,
    Str,
};
use Innmind\BlackBox\Set;
use Fixtures\Innmind\TimeContinuum\PointInTime;

return static function() {
    yield proof(
        'Cluster time is specifiable',
        given(
            PointInTime::any(),
        ),
        static function($assert, $start) {
            $local = Machine::new('local.dev')
                ->listenHttp(
                    static fn($request, $os) => Attempt::result(Response::of(
                        StatusCode::ok,
                        $request->protocolVersion(),
                        null,
                        Content::ofString($os->clock()->now()->format(Format::iso8601())),
                    )),
                );
            $cluster = Cluster::new()
                ->startClockAt($start)
                ->add($local)
                ->boot();

            $response = $cluster
                ->http(Request::of(
                    Url::of('http://local.dev/'),
                    Method::get,
                    ProtocolVersion::v11,
                ))
                ->unwrap();

            $assert->same(
                $start
                    ->changeOffset(Offset::utc())
                    ->format(Format::iso8601()),
                $response->body()->toString(),
            );
        },
    );

    yield proof(
        'Cluster time can be fast forwarded',
        given(
            PointInTime::any(),
            Set::integers()->between(1, 1_000),
        ),
        static function($assert, $start, $seconds) {
            $local = Machine::new('local.dev')
                ->listenHttp(
                    static fn($request, $os) => $os
                        ->process()
                        ->halt(Period::second($seconds))
                        ->map(static fn() => Response::of(
                            StatusCode::ok,
                            $request->protocolVersion(),
                            null,
                            Content::ofString($os->clock()->now()->format(Format::iso8601())),
                        )),
                );
            $cluster = Cluster::new()
                ->startClockAt($start)
                ->add($local)
                ->boot();

            $assert
                ->time(static function() use ($assert, $cluster, $start, $seconds) {
                    $response = $cluster
                        ->http(Request::of(
                            Url::of('http://local.dev/'),
                            Method::get,
                            ProtocolVersion::v11,
                        ))
                        ->unwrap();

                    $assert->same(
                        $start
                            ->changeOffset(Offset::utc())
                            ->goForward(Period::second($seconds))
                            ->format(Format::iso8601()),
                        $response->body()->toString(),
                    );
                })
                ->inLessThan()
                ->seconds(1);
        },
    );

    yield proof(
        'HTTP app can execute simulated process on the same machine',
        given(
            PointInTime::any(),
        ),
        static function($assert, $start) {
            $called = false;
            $local = Machine::new('local.dev')
                ->install(
                    'foo',
                    static function(
                        $command,
                        $builder,
                        $os,
                    ) use ($assert, &$called) {
                        $assert->same(
                            "foo 'display' '--option'",
                            $command->toString(),
                        );
                        $called = true;

                        return $builder->success([[
                            $os->clock()->now()->format(Format::iso8601()),
                            'output',
                        ]]);
                    },
                )
                ->listenHttp(
                    static fn($request, $os) => Attempt::result(Response::of(
                        StatusCode::ok,
                        $request->protocolVersion(),
                        null,
                        Content::ofChunks(
                            $os
                                ->control()
                                ->processes()
                                ->execute(
                                    Command::foreground('foo')
                                        ->withArgument('display')
                                        ->withOption('option'),
                                )
                                ->unwrap()
                                ->output()
                                ->map(static fn($chunk) => $chunk->data()),
                        ),
                    )),
                );
            $cluster = Cluster::new()
                ->startClockAt($start)
                ->add($local)
                ->boot();

            $response = $cluster
                ->http(Request::of(
                    Url::of('http://local.dev/'),
                    Method::get,
                    ProtocolVersion::v11,
                ))
                ->unwrap();

            $assert->true($called);
            $assert->same(
                $start
                    ->changeOffset(Offset::utc())
                    ->format(Format::iso8601()),
                $response->body()->toString(),
            );
        },
    );

    yield proof(
        'Machine can make HTTP calls to another machine',
        given(
            Set::strings(),
            Set::strings(),
        ),
        static function($assert, $input, $output) {
            $remote = Machine::new('remote.dev')
                ->listenHttp(
                    static function($request) use ($assert, $input, $output) {
                        $assert->same($input, $request->body()->toString());

                        return Attempt::result(Response::of(
                            StatusCode::ok,
                            $request->protocolVersion(),
                            null,
                            Content::ofString($output),
                        ));
                    },
                );
            $local = Machine::new('local.dev')
                ->listenHttp(
                    static fn($request, $os) => $os
                        ->remote()
                        ->http()(Request::of(
                            Url::of('http://remote.dev/'),
                            Method::post,
                            ProtocolVersion::v11,
                            null,
                            Content::ofString($input),
                        ))
                        ->attempt(static fn() => new RuntimeException('Failed to access remote server'))
                        ->map(static fn($success) => $success->response()),
                );
            $cluster = Cluster::new()
                ->add($local)
                ->add($remote)
                ->boot();

            $response = $cluster
                ->http(Request::of(
                    Url::of('http://local.dev/'),
                    Method::get,
                    ProtocolVersion::v11,
                ))
                ->unwrap();

            $assert->same(
                $output,
                $response->body()->toString(),
            );
        },
    );

    yield proof(
        'Streamed HTTP responses are accessed only once over the network',
        given(
            Set::strings(),
        ),
        static function($assert, $output) {
            $called = 0;
            $remote = Machine::new('remote.dev')
                ->listenHttp(
                    static function($request) use ($output, &$called) {
                        return Attempt::result(Response::of(
                            StatusCode::ok,
                            $request->protocolVersion(),
                            null,
                            Content::ofChunks(Sequence::lazy(static function() use ($output, &$called) {
                                ++$called;

                                yield Str::of($output);
                            })),
                        ));
                    },
                );
            $local = Machine::new('local.dev')
                ->listenHttp(
                    static fn($request, $os) => $os
                        ->remote()
                        ->http()(Request::of(
                            Url::of('http://remote.dev/'),
                            Method::get,
                            ProtocolVersion::v11,
                        ))
                        ->attempt(static fn() => new RuntimeException('Failed to access remote server'))
                        ->map(static fn($success) => $success->response()->body())
                        ->map(static fn($body) => Response::of(
                            StatusCode::ok,
                            $request->protocolVersion(),
                            null,
                            Content::ofString($body->toString().$body->toString()),
                        )),
                );
            $cluster = Cluster::new()
                ->add($local)
                ->add($remote)
                ->boot();

            $response = $cluster
                ->http(Request::of(
                    Url::of('http://local.dev/'),
                    Method::get,
                    ProtocolVersion::v11,
                ))
                ->unwrap();

            $assert->same(1, $called);
            $assert->same(
                $output.$output,
                $response->body()->toString(),
            );
        },
    );

    yield test(
        'Machines do not use the same operating system instance',
        static function($assert) {
            $remote = Machine::new('remote.dev')
                ->listenHttp(
                    static fn($request, $os) => Attempt::result(Response::of(
                        StatusCode::ok,
                        $request->protocolVersion(),
                        null,
                        Content::ofString(\spl_object_hash($os)),
                    )),
                );
            $local = Machine::new('local.dev')
                ->listenHttp(
                    static fn($request, $os) => $os
                        ->remote()
                        ->http()(Request::of(
                            Url::of('http://remote.dev/'),
                            Method::get,
                            ProtocolVersion::v11,
                        ))
                        ->attempt(static fn() => new RuntimeException('Failed to access remote server'))
                        ->map(static fn($success) => $success->response()->body())
                        ->map(static fn($body) => Response::of(
                            StatusCode::ok,
                            $request->protocolVersion(),
                            null,
                            Content::ofString(\spl_object_hash($os).'|'.$body->toString()),
                        )),
                );
            $cluster = Cluster::new()
                ->add($local)
                ->add($remote)
                ->boot();

            $response = $cluster
                ->http(Request::of(
                    Url::of('http://local.dev/'),
                    Method::get,
                    ProtocolVersion::v11,
                ))
                ->unwrap()
                ->body()
                ->toString();
            [$local, $remote] = \explode('|', $response);

            $assert
                ->expected($local)
                ->not()
                ->same($remote);
        },
    );

    yield test(
        'Machine get an error when accessing unknown machine over HTTP',
        static function($assert) {
            $local = Machine::new('local.dev')
                ->listenHttp(
                    static fn($request, $os) => $os
                        ->remote()
                        ->http()(Request::of(
                            Url::of('http://remote.dev/'),
                            Method::get,
                            ProtocolVersion::v11,
                        ))
                        ->match(
                            static fn($success) => Attempt::result($success->response()),
                            static fn($error) => match (true) {
                                $error instanceof ConnectionFailed => Attempt::result(Response::of(
                                    StatusCode::ok,
                                    $request->protocolVersion(),
                                    null,
                                    Content::ofString($error->reason()),
                                )),
                                default => Attempt::error(new RuntimeException),
                            },
                        ),
                );
            $cluster = Cluster::new()
                ->add($local)
                ->boot();

            $response = $cluster
                ->http(Request::of(
                    Url::of('http://local.dev/'),
                    Method::get,
                    ProtocolVersion::v11,
                ))
                ->unwrap();

            $assert->same(
                'Could not resolve host: remote.dev',
                $response->body()->toString(),
            );
        },
    );
};
