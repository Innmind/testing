<?php
declare(strict_types = 1);

use Innmind\Testing\Factory;
use Innmind\TimeContinuum\Period;
use Fixtures\Innmind\TimeContinuum\PointInTime;

return static function() {
    yield proof(
        'The clock always advance',
        given(
            PointInTime::any(),
        ),
        static function($assert, $point) {
            $os = Factory::new()
                ->startClockAt($point)
                ->build();
            $os->process()->halt(Period::microsecond(1));

            $now = $os->clock()->now();
            $assert->true($now->aheadOf($point));
            $os->process()->halt(Period::microsecond(1));
            $assert->true(
                $os->clock()->now()->aheadOf($now),
            );
        },
    );
};
