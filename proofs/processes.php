<?php
declare(strict_types = 1);

use Innmind\Testing\Factory;
use Innmind\Server\Control\Server\Command;
use Innmind\BlackBox\Set;

return static function() {
    yield proof(
        'Allow to intercept executables',
        given(
            Set::sequence(Set::strings()),
        ),
        static function($assert, $output) {
            $os = Factory::new()
                ->handleExecutable(
                    'foo',
                    static function(
                        $command,
                        $builder,
                    ) use ($assert, $output) {
                        $assert->same(
                            "foo 'display' '--option'",
                            $command->toString(),
                        );

                        return $builder->success(\array_map(
                            static fn($chunk) => [$chunk, 'output'],
                            $output,
                        ));
                    },
                )
                ->build();

            $assert->same(
                $output,
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
                    ->map(static fn($chunk) => $chunk->data()->toString())
                    ->toList(),
            );
        },
    );

    // todo prove the executables have access to the new os by checking the filesystem
};
