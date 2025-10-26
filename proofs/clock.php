<?php
declare(strict_types = 1);

use Innmind\Testing\Factory;
use Innmind\TimeContinuum\Format;
use Fixtures\Innmind\TimeContinuum\PointInTime;

return static function() {
    yield proof(
        'The clock always advance',
        given(
            PointInTime::any(),
        ),
        static function($assert, $point) {
            $os = Factory::new()
                ->startClockAt($point->format(Format::iso8601()))
                ->build();
            $now = $os->clock()->now();

            $assert->true($now->aheadOf($point));
            $assert->true(
                $os->clock()->now()->aheadOf($now),
            );
        },
    );
};
