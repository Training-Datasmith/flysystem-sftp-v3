<?php

declare(strict_types=1);

namespace League\Flysystem\PhpseclibV3\Tests;

use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\PhpseclibV3\UnableToAuthenticate;
use League\Flysystem\PhpseclibV3\UnableToLoadPrivateKey;
use League\Flysystem\PhpseclibV3\Tests\Support\ReflectionTestCase;
use phpseclib3\Crypt\Common\AsymmetricKey;
use phpseclib3\Crypt\RSA;
use phpseclib3\Net\SFTP;
use RuntimeException;

final class SftpConnectionProviderKeyAndAuthTest extends ReflectionTestCase
{
    public function testInlinePemLoads(): void
    {
        $key = RSA::createKey(512);
        $pem = (string) $key->toString('PKCS8');
        $provider = $this->providerWithKey($pem);
        $loaded = $this->invokePrivate($provider, 'loadPrivateKey');

        self::assertInstanceOf(AsymmetricKey::class, $loaded);
        $publicPem = (string) $key->getPublicKey()->toString('PKCS8');
        self::assertNotSame('', $publicPem);
        self::assertSame($publicPem, (string) $loaded->getPublicKey()->toString('PKCS8'));
    }

    public function testPemPathIsReadFromDisk(): void
    {
        $key = RSA::createKey(512);
        $pem = (string) $key->toString('PKCS8');
        $path = tempnam(sys_get_temp_dir(), 'pem');
        file_put_contents($path, $pem);
        $provider = $this->providerWithKey($path);
        $loaded = $this->invokePrivate($provider, 'loadPrivateKey');

        self::assertInstanceOf(AsymmetricKey::class, $loaded);
        @unlink($path);
    }

    public function testPassphraseRoundTrip(): void
    {
        $key = RSA::createKey(512)->withPassword('test-passphrase');
        $pem = (string) $key->toString('PKCS8');
        $provider = new SftpConnectionProvider('h', 'u', null, $pem, 'test-passphrase');
        $loaded = $this->invokePrivate($provider, 'loadPrivateKey');

        self::assertSame(
            (string) $key->getPublicKey()->toString('PKCS8'),
            (string) $loaded->getPublicKey()->toString('PKCS8')
        );
    }

    public function testWrongPassphraseThrowsUnableToLoadPrivateKey(): void
    {
        $key = RSA::createKey(512)->withPassword('test-passphrase');
        $pem = (string) $key->toString('PKCS8');
        $provider = new SftpConnectionProvider('h', 'u', null, $pem, 'wrong');

        try {
            $this->invokePrivate($provider, 'loadPrivateKey');
            self::fail('Expected UnableToLoadPrivateKey');
        } catch (UnableToLoadPrivateKey $exception) {
            self::assertNotNull($exception->getPrevious());
        }
    }

    public function testGarbageKeyThrowsUnableToLoadPrivateKey(): void
    {
        $provider = $this->providerWithKey('not-a-key');

        try {
            $this->invokePrivate($provider, 'loadPrivateKey');
            self::fail('Expected UnableToLoadPrivateKey');
        } catch (UnableToLoadPrivateKey $exception) {
            self::assertNotNull($exception->getPrevious());
        }
    }

    public function testPasswordLoginSuccess(): void
    {
        $connection = new LoginRecordingSftp();
        $connection->queueLoginResult(true);
        $provider = new SftpConnectionProvider('h', 'user', 'secret');

        $this->invokePrivate($provider, 'authenticate', [$connection]);

        self::assertCount(1, $connection->attempts);
        self::assertSame('user', $connection->attempts[0][0]);
        self::assertSame('secret', $connection->attempts[0][1][0]);
    }

    public function testPasswordLoginFailureCarriesLastError(): void
    {
        $connection = new LoginRecordingSftp();
        $connection->queueLoginResult(false);
        $provider = new SftpConnectionProvider('h', 'user', 'secret');

        try {
            $this->invokePrivate($provider, 'authenticate', [$connection]);
            self::fail('Expected UnableToAuthenticate');
        } catch (UnableToAuthenticate $exception) {
            self::assertSame('denied', $exception->connectionError());
        }
    }

    public function testPrivateKeySuccessDoesNotTryThePassword(): void
    {
        $connection = new LoginRecordingSftp();
        $key = RSA::createKey(512);
        $connection->queueLoginResult(true);
        $provider = new SftpConnectionProvider('h', 'user', 'secret', (string) $key->toString('PKCS8'));

        $this->invokePrivate($provider, 'authenticate', [$connection]);

        self::assertCount(1, $connection->attempts);
        self::assertInstanceOf(AsymmetricKey::class, $connection->attempts[0][1][0]);
    }

    public function testPrivateKeyFallsBackToPassword(): void
    {
        $connection = new LoginRecordingSftp();
        $key = RSA::createKey(512);
        $connection->queueLoginResult(false);
        $connection->queueLoginResult(true);
        $provider = new SftpConnectionProvider('h', 'user', 'secret', (string) $key->toString('PKCS8'));

        $this->invokePrivate($provider, 'authenticate', [$connection]);

        self::assertCount(2, $connection->attempts);
        self::assertSame('secret', $connection->attempts[1][1][0]);
    }

    public function testBothPrivateKeyAndPasswordFailure(): void
    {
        $connection = new LoginRecordingSftp();
        $key = RSA::createKey(512);
        $connection->queueLoginResult(false);
        $connection->queueLoginResult(false);
        $provider = new SftpConnectionProvider('h', 'user', 'secret', (string) $key->toString('PKCS8'));

        try {
            $this->invokePrivate($provider, 'authenticate', [$connection]);
            self::fail('Expected UnableToAuthenticate');
        } catch (UnableToAuthenticate $exception) {
            self::assertSame('denied', $exception->connectionError());
        }
    }

    public function testInvalidKeyDoesNotAttemptLogin(): void
    {
        $connection = new LoginRecordingSftp();
        $connection->loginShouldThrow = true;
        $provider = $this->providerWithKey('not-a-key');

        try {
            $this->invokePrivate($provider, 'authenticate', [$connection]);
            self::fail('Expected UnableToLoadPrivateKey');
        } catch (UnableToLoadPrivateKey $exception) {
            self::assertSame([], $connection->attempts);
            self::assertNotNull($exception->getPrevious());
        }
    }

    private function providerWithKey(string $privateKey): SftpConnectionProvider
    {
        return new SftpConnectionProvider('h', 'u', null, $privateKey);
    }
}

final class LoginRecordingSftp extends SFTP
{
    /** @var list<bool> */
    public array $results = [];

    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $attempts = [];

    public bool $loginShouldThrow = false;

    public function __construct()
    {
        parent::__construct(fopen('php://temp', 'w+'));
    }

    public function queueLoginResult(bool $result): void
    {
        $this->results[] = $result;
    }

    public function login($username, ...$args): bool
    {
        if ($this->loginShouldThrow) {
            throw new RuntimeException('login must not be called');
        }

        $this->attempts[] = [$username, $args];

        return array_shift($this->results) ?? false;
    }

    public function getLastError()
    {
        return 'denied';
    }
}
