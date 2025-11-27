<?php
declare(strict_types = 1);

use Innmind\Testing\Factory;
use Innmind\Http\{
    Request,
    Response,
    Response\StatusCode,
    Method,
    ProtocolVersion,
};
use Innmind\Filesystem\File\Content;
use Innmind\Url\Url;
use Innmind\Immutable\Attempt;
use Innmind\BlackBox\Set;

return static function() {
    yield proof(
        'Allow to intercept http calls',
        given(
            Set::strings(),
        ),
        static function($assert, $output) {
            $os = Factory::new()
                ->handleHttpDomain(
                    Url::of('http://example.com'),
                    static fn($request, $os) => Attempt::result(Response::of(
                        StatusCode::ok,
                        $request->protocolVersion(),
                        null,
                        Content::ofString($output),
                    )),
                )
                ->build();

            $assert->same(
                $output,
                $os
                    ->remote()
                    ->http()(Request::of(
                        Url::of('http://example.com/foo/bar'),
                        Method::get,
                        ProtocolVersion::v11,
                    ))
                    ->match(
                        static fn($success) => $success->response()->body()->toString(),
                        static fn() => null,
                    ),
            );
        },
    );

    // todo prove the os of the domain is not the same as the one being used
};
