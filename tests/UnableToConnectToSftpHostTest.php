<?php

declare(strict_types=1);

namespace League\Flysystem\PhpseclibV3\Tests;

use League\Flysystem\PhpseclibV3\UnableToConnectToSftpHost;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UnableToConnectToSftpHostTest extends TestCase
{
    public function testAtHostnameIncludesHostCodeAndPrevious(): void
    {
        $previous = new RuntimeException('underlying');
        $exception = UnableToConnectToSftpHost::atHostname('files.example', $previous);

        self::assertSame('Unable to connect to host: files.example', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}
