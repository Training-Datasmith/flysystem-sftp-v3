<?php

declare(strict_types=1);

namespace League\Flysystem\PhpseclibV3\Tests;

use League\Flysystem\PhpseclibV3\SimpleConnectivityChecker;
use phpseclib3\Net\SFTP;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SimpleConnectivityCheckerTest extends TestCase
{
    public function testCreateDoesNotPingALiveConnection(): void
    {
        $connection = new ControllableSftp(true, null);
        $checker = SimpleConnectivityChecker::create();

        self::assertTrue($checker->isConnected($connection));
    }

    public function testDisconnectedTransportReturnsFalseWithoutPing(): void
    {
        $connection = new ControllableSftp(false, null);
        $checker = SimpleConnectivityChecker::create();

        self::assertFalse($checker->isConnected($connection));
    }

    public function testWithUsingPingLeavesTheOriginalCheckerAlone(): void
    {
        $connection = new ControllableSftp(true, false);
        $checker = SimpleConnectivityChecker::create();
        $withPing = $checker->withUsingPing(true);

        self::assertTrue($checker->isConnected($connection));
        self::assertFalse($withPing->isConnected($connection));
    }

    public function testPingThrowableBecomesFalse(): void
    {
        $connection = new ControllableSftp(true, null);
        $checker = SimpleConnectivityChecker::create()->withUsingPing(true);

        self::assertFalse($checker->isConnected($connection));
    }
}

final class ControllableSftp extends SFTP
{
    public function __construct(
        private bool $transportConnected,
        private ?bool $pingResult,
    ) {
        parent::__construct(fopen('php://temp', 'w+'));
    }

    public function isConnected($level = 0): bool
    {
        return $this->transportConnected;
    }

    public function ping()
    {
        if ($this->pingResult === null) {
            throw new RuntimeException('ping failed');
        }

        return $this->pingResult;
    }
}
