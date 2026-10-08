<?php

declare(strict_types=1);

namespace League\Flysystem\PhpseclibV3\Tests\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

abstract class ReflectionTestCase extends TestCase
{
    /**
     * @param array<int, mixed> $args
     */
    protected function invokePrivate(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }

    protected function readPrivate(object $object, string $property): mixed
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($object);
    }
}
