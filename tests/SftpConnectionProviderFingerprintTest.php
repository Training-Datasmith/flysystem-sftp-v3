<?php

declare(strict_types=1);

namespace League\Flysystem\PhpseclibV3\Tests;

use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\PhpseclibV3\UnableToEstablishAuthenticityOfHost;
use League\Flysystem\PhpseclibV3\Tests\Support\ReflectionTestCase;
use phpseclib3\Net\SFTP;
use RuntimeException;

/**
 * Known-answer vectors for getFingerprintFromPublicKey (independent derivation documented in suite-results.md):
 * blob base64 Zml4dHVyZS1rZXktbWF0ZXJpYWw= ("fixture-key-material")
 * MD5 colon-hex: 20:e6:cd:e4:48:83:ad:59:08:42:43:63:b1:9c:fb:d4
 * SHA-512 colon-hex: 2e:5d:02:a1:c1:76:1b:6a:f1:49:6f:00:81:58:16:43:ac:81:47:77:70:1c:88:57:c5:aa:c2:5f:33:33:1e:bb:8c:0e:ba:bf:8e:d7:0f:b0:34:fd:8c:f0:dd:53:cf:d0:44:5d:4b:96:e2:c1:83:97:46:4e:8c:a1:af:89:35:42
 */
final class SftpConnectionProviderFingerprintTest extends ReflectionTestCase
{
    private const BLOB_B64 = 'Zml4dHVyZS1rZXktbWF0ZXJpYWw=';

    private const MD5_LITERAL = '20:e6:cd:e4:48:83:ad:59:08:42:43:63:b1:9c:fb:d4';

    private const SHA512_LITERAL = '2e:5d:02:a1:c1:76:1b:6a:f1:49:6f:00:81:58:16:43:ac:81:47:77:70:1c:88:57:c5:aa:c2:5f:33:33:1e:bb:8c:0e:ba:bf:8e:d7:0f:b0:34:fd:8c:f0:dd:53:cf:d0:44:5d:4b:96:e2:c1:83:97:46:4e:8c:a1:af:89:35:42';

    public function testSshRsaFingerprintIsTheMd5Literal(): void
    {
        $line = 'ssh-rsa ' . self::BLOB_B64 . ' fixture';
        $provider = new SftpConnectionProvider('h', 'u');

        $fingerprint = $this->invokePrivate($provider, 'getFingerprintFromPublicKey', [$line]);

        self::assertSame(self::MD5_LITERAL, $fingerprint);
    }

    public function testOtherAlgorithmsUseTheSha512Literal(): void
    {
        $line = 'ssh-ed25519 ' . self::BLOB_B64 . ' fixture';
        $provider = new SftpConnectionProvider('h', 'u');

        $fingerprint = $this->invokePrivate($provider, 'getFingerprintFromPublicKey', [$line]);

        self::assertSame(self::SHA512_LITERAL, $fingerprint);
    }

    public function testMatchingFingerprintIsCaseInsensitive(): void
    {
        $line = 'ssh-ed25519 ' . self::BLOB_B64 . ' fixture';
        $connection = new HostKeySftp($line);
        $provider = new SftpConnectionProvider('host.test', 'u', null, null, null, 22, false, 10, 4, strtoupper(self::SHA512_LITERAL));

        $this->invokePrivate($provider, 'checkFingerprint', [$connection]);
        self::addToAssertionCount(1);
    }

    public function testMismatchThrows(): void
    {
        $line = 'ssh-ed25519 ' . self::BLOB_B64 . ' fixture';
        $connection = new HostKeySftp($line);
        $provider = new SftpConnectionProvider('host.test', 'u', null, null, null, 22, false, 10, 4, '00:11');

        try {
            $this->invokePrivate($provider, 'checkFingerprint', [$connection]);
            self::fail('Expected UnableToEstablishAuthenticityOfHost');
        } catch (UnableToEstablishAuthenticityOfHost $exception) {
            self::assertStringContainsString('host.test', $exception->getMessage());
        }
    }

    public function testMissingServerKeyThrows(): void
    {
        $connection = new HostKeySftp(false);
        $provider = new SftpConnectionProvider('host.test', 'u', null, null, null, 22, false, 10, 4, 'aa');

        try {
            $this->invokePrivate($provider, 'checkFingerprint', [$connection]);
            self::fail('Expected UnableToEstablishAuthenticityOfHost');
        } catch (UnableToEstablishAuthenticityOfHost $exception) {
            self::assertStringContainsString('host.test', $exception->getMessage());
        }
    }

    public function testNullFingerprintDoesNotReadTheServerKey(): void
    {
        $connection = new HostKeySftp(throwsOnRead: true);
        $provider = new SftpConnectionProvider('host.test', 'u');

        $this->invokePrivate($provider, 'checkFingerprint', [$connection]);
        self::addToAssertionCount(1);
    }
}

final class HostKeySftp extends SFTP
{
    public function __construct(
        private string|bool $line = '',
        private bool $throwsOnRead = false,
    ) {
        parent::__construct(fopen('php://temp', 'w+'));
    }

    public function getServerPublicHostKey()
    {
        if ($this->throwsOnRead) {
            throw new RuntimeException('must not read host key');
        }

        return $this->line;
    }
}
