<?php

declare(strict_types=1);

namespace League\Flysystem\PhpseclibV3\Tests;

use League\Flysystem\PhpseclibV3\UnableToLoadPrivateKey;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UnableToLoadPrivateKeyTest extends TestCase
{
    public function testNullMessageFallsBackToTheDefault(): void
    {
        $previous = new RuntimeException('inner');
        $exception = new UnableToLoadPrivateKey(null, $previous);

        self::assertSame('Unable to load private key.', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testCustomMessageIsKept(): void
    {
        $exception = new UnableToLoadPrivateKey('custom message');

        self::assertSame('custom message', $exception->getMessage());
    }
}
