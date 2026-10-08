<?php

declare(strict_types=1);

namespace League\Flysystem\PhpseclibV3\Tests;

use League\Flysystem\Config;
use League\Flysystem\PhpseclibV3\FixatedConnectivityChecker;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\PhpseclibV3\UnableToAuthenticate;
use League\Flysystem\PhpseclibV3\UnableToConnectToSftpHost;
use League\Flysystem\PhpseclibV3\UnableToEstablishAuthenticityOfHost;
use League\Flysystem\PhpseclibV3\UnableToLoadPrivateKey;
use League\Flysystem\PhpseclibV3\Tests\Double\FixedMimeTypeDetector;
use League\Flysystem\PhpseclibV3\Tests\Support\LocalSftpServer;
use League\Flysystem\PhpseclibV3\Tests\Support\ReflectionTestCase;
use League\Flysystem\Visibility;
use phpseclib3\Net\SFTP;

final class SftpServerTest extends ReflectionTestCase
{
    private static LocalSftpServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalSftpServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        LocalSftpServer::stop();
    }

    public function testPasswordLoginIsCached(): void
    {
        $provider = $this->passwordProvider();
        $first = $provider->provideConnection();
        $second = $provider->provideConnection();

        self::assertInstanceOf(SFTP::class, $first);
        self::assertSame($first, $second);
        self::assertTrue($first->isConnected());
        $adapter = new SftpAdapter($this->passwordProvider(), self::$server->adapterRoot());
        $adapter->write('ping.txt', 'hello-fixed', new Config());
        self::assertSame('hello-fixed', $adapter->read('ping.txt'));
    }

    public function testDisconnectDropsTheCache(): void
    {
        $provider = $this->passwordProvider();
        $first = $provider->provideConnection();
        $provider->disconnect();
        $second = $provider->provideConnection();

        self::assertNotSame($first, $second);
        self::assertTrue($second->isConnected());
    }

    public function testBadPasswordThrowsUnableToAuthenticate(): void
    {
        $provider = new SftpConnectionProvider(
            '127.0.0.1',
            'sftptester',
            'wrong-password',
            null,
            null,
            self::$server->port(),
            false,
            2,
            0
        );

        try {
            $provider->provideConnection();
            self::fail('Expected UnableToAuthenticate');
        } catch (UnableToAuthenticate) {
            self::addToAssertionCount(1);
        }
    }

    public function testPrivateKeyFileLogsIn(): void
    {
        $provider = new SftpConnectionProvider(
            '127.0.0.1',
            'sftptester',
            null,
            self::$server->userPrivateKeyPath(),
            null,
            self::$server->port(),
            false,
            10,
            0
        );

        $connection = $provider->provideConnection();
        self::assertInstanceOf(SFTP::class, $connection);
        $adapter = new SftpAdapter(
            new SftpConnectionProvider(
                '127.0.0.1',
                'sftptester',
                null,
                self::$server->userPrivateKeyPath(),
                null,
                self::$server->port(),
                false,
                10,
                0
            ),
            self::$server->adapterRoot()
        );
        $adapter->write('key.txt', 'key-body', new Config());
        self::assertSame('key-body', $adapter->read('key.txt'));
    }

    public function testEncryptedPrivateKey(): void
    {
        $good = new SftpConnectionProvider(
            '127.0.0.1',
            'sftptester',
            null,
            self::$server->userEncryptedKeyPath(),
            'test-passphrase',
            self::$server->port(),
            false,
            10,
            0
        );
        self::assertInstanceOf(SFTP::class, $good->provideConnection());

        $bad = new SftpConnectionProvider(
            '127.0.0.1',
            'sftptester',
            null,
            self::$server->userEncryptedKeyPath(),
            'wrong-passphrase',
            self::$server->port(),
            false,
            10,
            0
        );

        try {
            $bad->provideConnection();
            self::fail('Expected UnableToLoadPrivateKey');
        } catch (UnableToLoadPrivateKey $exception) {
            self::assertNotNull($exception->getPrevious());
        }
    }

    public function testPrivateKeyFailureFallsBackToThePassword(): void
    {
        $provider = new SftpConnectionProvider(
            '127.0.0.1',
            'sftptester',
            'secret-pass',
            self::$server->userUnauthorizedKeyPath(),
            null,
            self::$server->port(),
            false,
            10,
            0
        );

        self::assertInstanceOf(SFTP::class, $provider->provideConnection());
    }

    public function testHostFingerprintIsEnforced(): void
    {
        $line = trim((string) file_get_contents(self::$server->hostPublicKeyPath()));
        $helper = new SftpConnectionProvider('h', 'u');
        $fingerprint = $this->invokePrivate($helper, 'getFingerprintFromPublicKey', [$line]);

        $good = new SftpConnectionProvider(
            '127.0.0.1',
            'sftptester',
            'secret-pass',
            null,
            null,
            self::$server->port(),
            false,
            10,
            0,
            $fingerprint
        );
        self::assertInstanceOf(SFTP::class, $good->provideConnection());

        $bad = new SftpConnectionProvider(
            '127.0.0.1',
            'sftptester',
            'secret-pass',
            null,
            null,
            self::$server->port(),
            false,
            10,
            0,
            '00:00'
        );

        try {
            $bad->provideConnection();
            self::fail('Expected UnableToEstablishAuthenticityOfHost');
        } catch (UnableToEstablishAuthenticityOfHost) {
            self::addToAssertionCount(1);
        }
    }

    public function testUnknownKexFailsClosed(): void
    {
        $provider = new SftpConnectionProvider(
            '127.0.0.1',
            'sftptester',
            'secret-pass',
            null,
            null,
            self::$server->port(),
            false,
            2,
            0,
            null,
            null,
            ['kex' => ['not-a-real-kex-name']]
        );

        try {
            $provider->provideConnection();
            self::fail('Expected UnableToConnectToSftpHost');
        } catch (UnableToConnectToSftpHost) {
            self::addToAssertionCount(1);
        }
    }

    public function testConnectivityCheckerRetriesThenThrows(): void
    {
        $checker = new FixatedConnectivityChecker(1000);
        $provider = new SftpConnectionProvider(
            '127.0.0.1',
            'sftptester',
            'secret-pass',
            null,
            null,
            self::$server->port(),
            false,
            1,
            2,
            null,
            $checker
        );

        try {
            $provider->provideConnection();
            self::fail('Expected UnableToConnectToSftpHost');
        } catch (UnableToConnectToSftpHost) {
            self::assertSame(3, $this->readPrivate($checker, 'numberOfTimesChecked'));
        }
    }

    public function testConnectivityCheckerCanRecover(): void
    {
        $checker = new FixatedConnectivityChecker(2);
        $provider = new SftpConnectionProvider(
            '127.0.0.1',
            'sftptester',
            'secret-pass',
            null,
            null,
            self::$server->port(),
            false,
            1,
            4,
            null,
            $checker
        );

        self::assertInstanceOf(SFTP::class, $provider->provideConnection());
        self::assertSame(2, $this->readPrivate($checker, 'numberOfTimesChecked'));
    }

    public function testDisableStatCacheFalseServesTheCachedSize(): void
    {
        $relative = 'stat-cache-' . bin2hex(random_bytes(4)) . '.txt';
        $absolute = self::$server->uploadDirectory() . '/' . $relative;

        $cached = SftpConnectionProvider::fromArray([
            'host' => '127.0.0.1',
            'username' => 'sftptester',
            'password' => 'secret-pass',
            'port' => self::$server->port(),
            'maxTries' => 0,
            'disableStatCache' => false,
        ]);
        $adapter = new SftpAdapter($cached, self::$server->adapterRoot());
        $adapter->write($relative, 'a', new Config());
        self::assertSame(1, $adapter->fileSize($relative)->fileSize());
        $written = file_put_contents($absolute, 'abcdef');
        self::assertSame(6, $written);
        self::assertSame(1, $adapter->fileSize($relative)->fileSize());
    }

    public function testDefaultDisableStatCacheReadsFreshSize(): void
    {
        $relative = 'stat-live-' . bin2hex(random_bytes(4)) . '.txt';
        $absolute = self::$server->uploadDirectory() . '/' . $relative;

        $live = SftpConnectionProvider::fromArray([
            'host' => '127.0.0.1',
            'username' => 'sftptester',
            'password' => 'secret-pass',
            'port' => self::$server->port(),
            'maxTries' => 0,
        ]);
        $adapter = new SftpAdapter($live, self::$server->adapterRoot());
        $adapter->write($relative, 'a', new Config());
        self::assertSame(1, $adapter->fileSize($relative)->fileSize());
        $written = file_put_contents($absolute, 'abcdef');
        self::assertSame(6, $written);
        self::assertSame(6, $adapter->fileSize($relative)->fileSize());
    }

    public function testAdapterRoundTripAgainstSshd(): void
    {
        $relativeDir = 'roundtrip-' . bin2hex(random_bytes(4));
        $provider = $this->passwordProvider();
        $adapter = new SftpAdapter(
            $provider,
            self::$server->adapterRoot(),
            null,
            new FixedMimeTypeDetector(fromContents: 'application/x-roundtrip')
        );

        $adapter->write($relativeDir . '/file.txt', 'hello-world', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        self::assertSame('hello-world', $adapter->read($relativeDir . '/file.txt'));
        self::assertTrue($adapter->fileExists($relativeDir . '/file.txt'));
        self::assertSame(11, $adapter->fileSize($relativeDir . '/file.txt')->fileSize());
        self::assertSame(Visibility::PUBLIC, $adapter->visibility($relativeDir . '/file.txt')->visibility());
        self::assertGreaterThan(0, $adapter->lastModified($relativeDir . '/file.txt')->lastModified());

        $adapter->createDirectory($relativeDir . '/nested', new Config());
        $listed = iterator_to_array($adapter->listContents($relativeDir, false));
        self::assertNotEmpty($listed);

        $adapter->copy($relativeDir . '/file.txt', $relativeDir . '/copy.txt', new Config());
        $adapter->move($relativeDir . '/copy.txt', $relativeDir . '/moved.txt', new Config());
        self::assertTrue($adapter->fileExists($relativeDir . '/moved.txt'));
        $adapter->delete($relativeDir . '/moved.txt');
        $adapter->deleteDirectory($relativeDir);
        self::assertFalse($adapter->directoryExists($relativeDir));
    }

    private function passwordProvider(): SftpConnectionProvider
    {
        return new SftpConnectionProvider(
            '127.0.0.1',
            'sftptester',
            'secret-pass',
            null,
            null,
            self::$server->port(),
            false,
            10,
            0
        );
    }
}
