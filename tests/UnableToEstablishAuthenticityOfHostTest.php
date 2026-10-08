<?php

declare(strict_types=1);

namespace League\Flysystem\PhpseclibV3\Tests;

use League\Flysystem\PhpseclibV3\UnableToEstablishAuthenticityOfHost;
use PHPUnit\Framework\TestCase;

final class UnableToEstablishAuthenticityOfHostTest extends TestCase
{
    public function testMessageNamesTheHost(): void
    {
        $exception = UnableToEstablishAuthenticityOfHost::becauseTheAuthenticityCantBeEstablished('files.example');

        self::assertSame("The authenticity of host files.example can't be established.", $exception->getMessage());
    }
}
