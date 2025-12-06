<?php
declare(strict_types = 1);

use Innmind\Testing\{
    Machine,
    Cluster,
    Network,
    Exception\CouldNotResolveHost,
};
use Innmind\Server\Control\Server\Command;
use Innmind\HttpTransport\ConnectionFailed;
use Innmind\Http\{
    Request,
    Response,
    Response\StatusCode,
    Method,
    ProtocolVersion,
    Headers,
    Header\Date,
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
    Monoid\Concat,
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
                ->listen(
                    Machine\HTTP::of(static fn($request, $os) => Attempt::result(Response::of(
                        StatusCode::ok,
                        $request->protocolVersion(),
                        null,
                        Content::ofString($os->clock()->now()->format(Format::iso8601())),
                    ))),
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
                ->listen(
                    Machine\HTTP::of(static fn($request, $os) => $os
                        ->process()
                        ->halt(Period::second($seconds))
                        ->map(static fn() => Response::of(
                            StatusCode::ok,
                            $request->protocolVersion(),
                            null,
                            Content::ofString($os->clock()->now()->format(Format::iso8601())),
                        )),
                    ),
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
        'Cluster time can be sped up',
        given(
            PointInTime::any(),
            Set::integers()->between(2, 10),
        ),
        static function($assert, $start, $speed) {
            $local = Machine::new('local.dev')
                ->listen(
                    Machine\HTTP::of(static fn($request, $os) => $os
                        ->process()
                        ->halt(Period::second(1))
                        ->map(static fn() => Response::of(
                            StatusCode::ok,
                            $request->protocolVersion(),
                            null,
                            Content::ofString($os->clock()->now()->format(Format::iso8601())),
                        )),
                    ),
                );
            $cluster = Cluster::new()
                ->startClockAt($start)
                ->speedUpTimeBy($speed)
                ->add($local)
                ->boot();

            $assert
                ->time(static function() use ($assert, $cluster, $start, $speed) {
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
                            ->goForward(Period::second($speed))
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
                    Machine\CLI::of(static function(
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
                    }),
                )
                ->listen(
                    Machine\HTTP::of(static fn($request, $os) => Attempt::result(Response::of(
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
                    ))),
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
                ->listen(
                    Machine\HTTP::of(static function($request) use ($assert, $input, $output) {
                        $assert->same($input, $request->body()->toString());

                        return Attempt::result(Response::of(
                            StatusCode::ok,
                            $request->protocolVersion(),
                            null,
                            Content::ofString($output),
                        ));
                    }),
                );
            $local = Machine::new('local.dev')
                ->listen(
                    Machine\HTTP::of(static fn($request, $os) => $os
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
                    ),
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
                ->listen(
                    Machine\HTTP::of(static function($request) use ($output, &$called) {
                        return Attempt::result(Response::of(
                            StatusCode::ok,
                            $request->protocolVersion(),
                            null,
                            Content::ofChunks(Sequence::lazy(static function() use ($output, &$called) {
                                ++$called;

                                yield Str::of($output);
                            })),
                        ));
                    }),
                );
            $local = Machine::new('local.dev')
                ->listen(
                    Machine\HTTP::of(static fn($request, $os) => $os
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
                    ),
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
                ->listen(
                    Machine\HTTP::of(static fn($request, $os) => Attempt::result(Response::of(
                        StatusCode::ok,
                        $request->protocolVersion(),
                        null,
                        Content::ofString(\spl_object_hash($os)),
                    ))),
                );
            $local = Machine::new('local.dev')
                ->listen(
                    Machine\HTTP::of(static fn($request, $os) => $os
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
                    ),
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
                ->listen(
                    Machine\HTTP::of(static fn($request, $os) => $os
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

    yield test(
        'Cluster get an error when accessing unknown machine over SSH',
        static function($assert) {
            $cluster = Cluster::new()->boot();

            $e = $cluster
                ->ssh('local.dev')
                ->match(
                    static fn() => null,
                    static fn($e) => $e,
                );

            $assert
                ->object($e)
                ->instance(CouldNotResolveHost::class);
        },
    );

    yield proof(
        'Time can drift between machines',
        given(
            // Below the second of drift it's hard to assert it's correctly
            // applied as the clock still advances. So doing time math at this
            // level of precision regularly fails with an off by one error.
            Set::either(
                Set::integers()->between(1_000, 3_000),
                Set::integers()->between(-3_000, -1_000),
            ),
        ),
        static function($assert, $drift) {
            $remote = Machine::new('remote.dev')
                ->listen(
                    Machine\HTTP::of(static fn($request, $os) => Attempt::result(Response::of(
                        StatusCode::ok,
                        $request->protocolVersion(),
                        null,
                        Content::ofString(\sprintf(
                            '%s|%s',
                            $request->body()->toString(),
                            $os->clock()->now()->format(Format::iso8601()),
                        )),
                    ))),
                );
            $local = Machine::new('local.dev')
                ->driftClockBy(Machine\Clock\Drift::of(0, $drift))
                ->listen(
                    Machine\HTTP::of(static fn($request, $os) => $os
                        ->remote()
                        ->http()(Request::of(
                            Url::of('http://remote.dev/'),
                            Method::post,
                            ProtocolVersion::v11,
                            Headers::of(
                                Date::of($os->clock()->now()), // to force accessing the second drift
                            ),
                            Content::ofString($os->clock()->now()->format(Format::iso8601())),
                        ))
                        ->attempt(static fn() => new RuntimeException('Failed to access remote server'))
                        ->map(static fn($success) => $success->response()->body())
                        ->map(static fn($body) => Response::of(
                            StatusCode::ok,
                            $request->protocolVersion(),
                            null,
                            Content::ofString(\sprintf(
                                '%s|%s',
                                $body->toString(),
                                $os->clock()->now()->format(Format::iso8601()),
                            )),
                        )),
                    ),
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
            [$drifted, $remote, $resynced] = \explode('|', $response);
            // remove last millisecond as the execution of the code can elapse
            // over 2 miliseconds.
            $drifted = \substr($drifted, 0, -1);
            $remote = \substr($remote, 0, -1);
            $resynced = \substr($resynced, 0, -1);

            $assert
                ->expected($drifted)
                ->not()
                ->same($remote);
            $assert->same($remote, $resynced);
        },
    );

    yield proof(
        'Network latencies',
        given(
            PointInTime::any(),
            // Below the second of latency it's hard to assert it's correctly
            // applied as the clock still advances. So doing time math at this
            // level of precision regularly fails with an off by one error.
            Set::integers()->between(1_000, 3_000),
            Set::integers()->between(1_000, 3_000),
        ),
        static function($assert, $start, $in, $out) {
            $remote = Machine::new('remote.dev')
                ->listen(
                    Machine\HTTP::of(static fn($request, $os) => Attempt::result(Response::of(
                        StatusCode::ok,
                        $request->protocolVersion(),
                    ))),
                );
            $local = Machine::new('local.dev')
                ->listen(
                    Machine\HTTP::of(static fn($request, $os) => $os
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
                            Content::ofString($os->clock()->now()->format(Format::iso8601())),
                        )),
                    ),
                );
            $cluster = Cluster::new()
                ->add($local)
                ->add($remote)
                ->startClockAt($start)
                ->withNetworkLatency(Network\Latency::of($in, $out))
                ->boot();

            $now = $cluster
                ->http(Request::of(
                    Url::of('http://local.dev/'),
                    Method::get,
                    ProtocolVersion::v11,
                ))
                ->unwrap()
                ->body()
                ->toString();

            $assert->same(
                $start
                    ->changeOffset(Offset::utc())
                    ->goForward(Period::millisecond($in + $in + $out)) // 2 $in as we make call local.dev over http
                    ->format(Format::iso8601()),
                $now,
            );
        },
    );

    yield proof(
        'Machine OS is configurable',
        given(
            // France was on UTC time for part of 20th century
            PointInTime::after('2000-01-01'),
        ),
        static function($assert, $start) {
            $local = Machine::new('local.dev')
                ->listen(
                    Machine\HTTP::of(static fn($request, $os) => Attempt::result(Response::of(
                        StatusCode::ok,
                        $request->protocolVersion(),
                        null,
                        Content::ofString(\sprintf(
                            '%s|%s',
                            $os->clock()->now()->format(Format::iso8601()),
                            $os->clock()->now()->changeOffset(Offset::utc())->format(Format::iso8601()),
                        )),
                    ))),
                )
                ->configureOperatingSystem(
                    static fn($config) => $config->mapClock(
                        static fn($clock) => $clock->switch(
                            static fn($timezones) => $timezones->europe()->paris(),
                        ),
                    ),
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
                ->unwrap()
                ->body()
                ->toString();
            [$paris, $utc] = \explode('|', $response);

            $assert
                ->expected(
                    $start
                        ->changeOffset(Offset::utc())
                        ->format(Format::iso8601()),
                )
                ->same($utc)
                ->not()
                ->same($paris);
        },
    );

    yield proof(
        'HTTP app has access to machine environment variables',
        given(
            Set::strings(),
            Set::strings(),
        ),
        static function($assert, $key, $value) {
            $local = Machine::new('local.dev')
                ->listen(
                    Machine\HTTP::of(static fn($request, $_, $environment) => Attempt::result(Response::of(
                        StatusCode::ok,
                        $request->protocolVersion(),
                        null,
                        Content::ofString(\implode(
                            '',
                            $environment
                                ->map(static fn($key, $value) => $key.$value)
                                ->values()
                                ->toList(),
                        )),
                    ))),
                )
                ->withEnvironment($key, $value);
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
                $key.$value,
                $response->body()->toString(),
            );
        },
    );

    yield proof(
        'CLI app has access to machine environment variables',
        given(
            Set::strings(),
            Set::strings(),
        ),
        static function($assert, $key, $value) {
            $local = Machine::new('local.dev')
                ->install(
                    'foo',
                    Machine\CLI::of(static function(
                        $command,
                        $builder,
                        $_,
                        $environment,
                    ) use ($assert) {
                        $assert->same(
                            "foo 'display' '--option'",
                            $command->toString(),
                        );

                        return $builder->success([[
                            \implode(
                                '',
                                $environment
                                    ->map(static fn($key, $value) => $key.$value)
                                    ->values()
                                    ->toList(),
                            ),
                            'output',
                        ]]);
                    }),
                )
                ->listen(
                    Machine\HTTP::of(static fn($request, $os) => Attempt::result(Response::of(
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
                    ))),
                )
                ->withEnvironment($key, $value);
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
                $key.$value,
                $response->body()->toString(),
            );
        },
    );

    yield proof(
        'Machine is accessible over ssh',
        given(
            PointInTime::any(),
        ),
        static function($assert, $start) {
            $local = Machine::new('local.dev')
                ->install(
                    'foo',
                    Machine\CLI::of(static function(
                        $command,
                        $builder,
                        $os,
                    ) use ($assert) {
                        $assert->same(
                            "foo 'display' '--option'",
                            $command->toString(),
                        );

                        return $builder->success([[
                            $os->clock()->now()->format(Format::iso8601()),
                            'output',
                        ]]);
                    }),
                );
            $cluster = Cluster::new()
                ->startClockAt($start)
                ->add($local)
                ->boot();

            $output = $cluster
                ->ssh('local.dev')
                ->flatMap(static fn($run) => $run(
                    Command::foreground('foo')
                        ->withArgument('display')
                        ->withOption('option'),
                ))
                ->unwrap()
                ->output()
                ->map(static fn($chunk) => $chunk->data())
                ->fold(new Concat)
                ->toString();

            $assert->same(
                $start
                    ->changeOffset(Offset::utc())
                    ->format(Format::iso8601()),
                $output,
            );
        },
    );

    yield proof(
        'Machine can execute processes on another machine over ssh',
        given(
            Set::strings(),
        ),
        static function($assert, $expected) {
            $remote = Machine::new('remote.dev')
                ->install(
                    'bar',
                    Machine\CLI::of(static function(
                        $command,
                        $builder,
                    ) use ($assert, $expected) {
                        $assert->same(
                            "bar 'display' '--option'",
                            $command->toString(),
                        );

                        return $builder->success([[
                            $expected,
                            'output',
                        ]]);
                    }),
                );
            $local = Machine::new('local.dev')
                ->install(
                    'foo',
                    Machine\CLI::of(static function(
                        $_,
                        $builder,
                        $os,
                    ) use ($assert) {
                        $output = $os
                            ->remote()
                            ->ssh(Url::of('ssh://watev@remote.dev/'))
                            ->processes()
                            ->execute(
                                Command::foreground('bar')
                                    ->withArgument('display')
                                    ->withOption('option'),
                            )
                            ->unwrap()
                            ->output()
                            ->map(static fn($chunk) => [
                                $chunk->data()->toString(),
                                $chunk->type()->name,
                            ])
                            ->toList();

                        return $builder->success($output);
                    }),
                );
            $cluster = Cluster::new()
                ->add($local)
                ->add($remote)
                ->boot();

            $output = $cluster
                ->ssh('local.dev')
                ->flatMap(static fn($run) => $run(Command::foreground('foo')))
                ->unwrap()
                ->output()
                ->map(static fn($chunk) => $chunk->data())
                ->fold(new Concat)
                ->toString();

            $assert->same(
                $expected,
                $output,
            );
        },
    );

    yield proof(
        'Machine can execute piped commands',
        given(
            Set::strings(),
            Set::strings(),
            Set::strings(),
        ),
        static function($assert, $input, $intermediary, $expected) {
            $local = Machine::new('local.dev')
                ->install(
                    'bar',
                    Machine\CLI::of(static function(
                        $command,
                        $builder,
                    ) use ($assert, $intermediary, $expected) {
                        $assert->same(
                            $intermediary,
                            $command->input()->match(
                                static fn($input) => $input->toString(),
                                static fn() => null,
                            ),
                        );

                        return $builder->success([[
                            $expected,
                            'output',
                        ]]);
                    }),
                )
                ->install(
                    'foo',
                    Machine\CLI::of(static function(
                        $command,
                        $builder,
                    ) use ($assert, $input, $intermediary) {
                        $assert->same(
                            $input,
                            $command->input()->match(
                                static fn($input) => $input->toString(),
                                static fn() => null,
                            ),
                        );

                        return $builder->success([[
                            $intermediary,
                            'output',
                        ]]);
                    }),
                );
            $cluster = Cluster::new()
                ->add($local)
                ->boot();

            $output = $cluster
                ->ssh('local.dev')
                ->flatMap(static fn($run) => $run(
                    Command::foreground('foo')
                        ->withInput(Content::ofString($input))
                        ->pipe(Command::foreground('bar')),
                ))
                ->unwrap()
                ->output()
                ->map(static fn($chunk) => $chunk->data())
                ->fold(new Concat)
                ->toString();

            $assert->same(
                $expected,
                $output,
            );
        },
    );
};
