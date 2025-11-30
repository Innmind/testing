<?php
declare(strict_types = 1);

use Innmind\Testing\{
    Machine,
    Cluster,
};
use Innmind\Server\Control\Server\Command;
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
use Innmind\Immutable\Attempt;
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
};
