<?php

declare(strict_types=1);

namespace League\Flysystem\PhpseclibV3\Tests;

use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\PhpseclibV3\UnableToConnectToSftpHost;
use PHPUnit\Framework\TestCase;
use Throwable;

final class SftpConnectionProviderNetworkTest extends TestCase
{
    public function testRefusedConnectionIsWrapped(): void
    {
        if (! extension_loaded('sockets')) {
            self::markTestSkipped('sockets extension required');
        }

        $port = $this->refusedPort();
        $provider = new SftpConnectionProvider('127.0.0.1', 'u', 'p', null, null, $port, false, 1, 0);

        try {
            $provider->provideConnection();
            self::fail('Expected UnableToConnectToSftpHost');
        } catch (UnableToConnectToSftpHost $exception) {
            self::assertStringContainsString('127.0.0.1', $exception->getMessage());
            self::assertInstanceOf(Throwable::class, $exception->getPrevious());
        }
    }

    public function testDisconnectBeforeAnyConnectionIsHarmless(): void
    {
        $provider = new SftpConnectionProvider('127.0.0.1', 'u');
        $provider->disconnect();
        $provider->disconnect();
        self::addToAssertionCount(1);
    }

    private function refusedPort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertNotFalse($socket);
        socket_bind($socket, '127.0.0.1', 0);
        $address = '';
        socket_getsockname($socket, $address, $port);
        socket_close($socket);

        return $port;
    }
}
