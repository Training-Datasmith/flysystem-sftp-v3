<?php

declare(strict_types=1);

namespace League\Flysystem\PhpseclibV3\Tests;

use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\PhpseclibV3\SimpleConnectivityChecker;
use League\Flysystem\PhpseclibV3\Tests\Support\ReflectionTestCase;

final class SftpConnectionProviderOptionsTest extends ReflectionTestCase
{
    public function testFromArrayCopiesEveryOption(): void
    {
        $checker = new SimpleConnectivityChecker();
        $provider = SftpConnectionProvider::fromArray([
            'host' => 'example.test',
            'username' => 'alice',
            'password' => 'pw',
            'privateKey' => 'inline-key',
            'passphrase' => 'pp',
            'port' => 2224,
            'useAgent' => true,
            'timeout' => 7,
            'maxTries' => 2,
            'hostFingerprint' => 'aa:bb',
            'connectivityChecker' => $checker,
            'preferredAlgorithms' => ['kex' => ['curve25519-sha256']],
            'disableStatCache' => false,
        ]);

        self::assertSame('example.test', $this->readPrivate($provider, 'host'));
        self::assertSame('alice', $this->readPrivate($provider, 'username'));
        self::assertSame('pw', $this->readPrivate($provider, 'password'));
        self::assertSame('inline-key', $this->readPrivate($provider, 'privateKey'));
        self::assertSame('pp', $this->readPrivate($provider, 'passphrase'));
        self::assertSame(2224, $this->readPrivate($provider, 'port'));
        self::assertTrue($this->readPrivate($provider, 'useAgent'));
        self::assertSame(7, $this->readPrivate($provider, 'timeout'));
        self::assertSame(2, $this->readPrivate($provider, 'maxTries'));
        self::assertSame('aa:bb', $this->readPrivate($provider, 'hostFingerprint'));
        self::assertSame(['kex' => ['curve25519-sha256']], $this->readPrivate($provider, 'preferredAlgorithms'));
        self::assertFalse($this->readPrivate($provider, 'disableStatCache'));
        self::assertSame($checker, $this->readPrivate($provider, 'connectivityChecker'));
    }

    public function testFromArrayDefaults(): void
    {
        $provider = SftpConnectionProvider::fromArray([
            'host' => 'h',
            'username' => 'u',
        ]);

        self::assertSame(22, $this->readPrivate($provider, 'port'));
        self::assertFalse($this->readPrivate($provider, 'useAgent'));
        self::assertSame(10, $this->readPrivate($provider, 'timeout'));
        self::assertSame(4, $this->readPrivate($provider, 'maxTries'));
        self::assertNull($this->readPrivate($provider, 'hostFingerprint'));
        self::assertSame([], $this->readPrivate($provider, 'preferredAlgorithms'));
        self::assertTrue($this->readPrivate($provider, 'disableStatCache'));
        self::assertNull($this->readPrivate($provider, 'password'));
        self::assertInstanceOf(SimpleConnectivityChecker::class, $this->readPrivate($provider, 'connectivityChecker'));
    }
}
