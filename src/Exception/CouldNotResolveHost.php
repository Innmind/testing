<?php
declare(strict_types = 1);

namespace Innmind\Testing\Exception;

/**
 * @internal
 */
final class CouldNotResolveHost extends \RuntimeException
{
    public function __construct(string $host)
    {
        parent::__construct(\sprintf(
            'Could not resolve host: %s',
            $host,
        ));
    }
}
