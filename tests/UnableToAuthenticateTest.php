<?php

declare(strict_types=1);

namespace League\Flysystem\PhpseclibV3\Tests;

use League\Flysystem\FilesystemException;
use League\Flysystem\PhpseclibV3\UnableToAuthenticate;
use PHPUnit\Framework\TestCase;

final class UnableToAuthenticateTest extends TestCase
{
    public function testWithPasswordStoresMessageAndLastError(): void
    {
        $exception = UnableToAuthenticate::withPassword('denied');

        self::assertSame('Unable to authenticate using a password.', $exception->getMessage());
        self::assertSame('denied', $exception->connectionError());
        self::assertInstanceOf(FilesystemException::class, $exception);
    }

    public function testWithPrivateKeyAllowsANullLastError(): void
    {
        $exception = UnableToAuthenticate::withPrivateKey();

        self::assertSame('Unable to authenticate using a private key.', $exception->getMessage());
        self::assertNull($exception->connectionError());
    }

    public function testWithSshAgentMessage(): void
    {
        $exception = UnableToAuthenticate::withSshAgent();

        self::assertSame('Unable to authenticate using an SSH agent.', $exception->getMessage());
    }
}
