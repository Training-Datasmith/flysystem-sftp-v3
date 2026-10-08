<?php

declare(strict_types=1);

namespace League\Flysystem\PhpseclibV3\Tests;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\Tests\Double\CallbackConnectionProvider;
use League\Flysystem\PhpseclibV3\Tests\Double\FixedMimeTypeDetector;
use League\Flysystem\PhpseclibV3\Tests\Double\FixedVisibilityConverter;
use League\Flysystem\PhpseclibV3\Tests\Double\RecordingSftp;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SftpAdapterTest extends TestCase
{
    private RecordingSftp $sftp;

    protected function setUp(): void
    {
        $this->sftp = new RecordingSftp();
        $this->sftp->seedDirectory('/upload/');
    }

    public function testWriteThenReadRoundTrip(): void
    {
        $adapter = $this->adapter();
        $adapter->write('note.txt', 'hello-fixed', new Config());

        self::assertSame('hello-fixed', $adapter->read('note.txt'));
    }

    public function testPathsArePrefixedWithTheRoot(): void
    {
        $adapter = $this->adapter();
        $adapter->write('dir/note.txt', 'x', new Config());

        self::assertTrue($this->sftp->is_file('/upload/dir/note.txt'));
        self::assertTrue($adapter->fileExists('dir/note.txt'));
    }

    public function testWriteCreatesMissingParents(): void
    {
        $adapter = $this->adapter();
        $adapter->write('a/b/c.txt', 'x', new Config());

        self::assertTrue($this->sftp->is_dir('/upload/a/b/'));
    }

    public function testParentDirectoryUsesDirectoryVisibility(): void
    {
        $adapter = $this->adapter();
        $adapter->write('segment/path.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));

        self::assertSame(0702, $this->lastMkdirMode('/upload/segment'));
        self::assertSame(0641, $this->lastChmodMode('/upload/segment/path.txt'));
    }

    public function testDirectoryVisibilityOnWriteIsUsedForTheParent(): void
    {
        $adapter = $this->adapter();
        $adapter->write('nested/file.txt', 'x', new Config([
            Config::OPTION_DIRECTORY_VISIBILITY => Visibility::PUBLIC,
        ]));

        self::assertSame(0751, $this->lastMkdirMode('/upload/nested'));
    }

    public function testPutFailureThrowsUnableToWriteFile(): void
    {
        $this->sftp->failOnPut('/upload/fail.txt');
        $adapter = $this->adapter();

        try {
            $adapter->write('fail.txt', 'x', new Config());
            self::fail('Expected UnableToWriteFile');
        } catch (UnableToWriteFile $exception) {
            self::assertStringContainsString('not able to write the file', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testChmodFailureWhileWritingIsWrapped(): void
    {
        $this->sftp->failOnChmod('/upload/path.txt');
        $adapter = $this->adapter();

        try {
            $adapter->write('path.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
            self::fail('Expected UnableToWriteFile');
        } catch (UnableToWriteFile $exception) {
            self::assertInstanceOf(UnableToSetVisibility::class, $exception->getPrevious());
        }
    }

    public function testConnectionFailureWhileWritingIsWrapped(): void
    {
        $adapter = new SftpAdapter(
            new CallbackConnectionProvider(static fn () => throw new RuntimeException('down')),
            '/upload'
        );

        try {
            $adapter->write('x.txt', 'x', new Config());
            self::fail('Expected UnableToWriteFile');
        } catch (UnableToWriteFile $exception) {
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
    }

    public function testWriteStreamRoundTrip(): void
    {
        $adapter = $this->adapter();
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, 'stream-bytes');
        rewind($stream);
        $adapter->writeStream('stream.txt', $stream, new Config());
        fclose($stream);

        self::assertSame('stream-bytes', $adapter->read('stream.txt'));
    }

    public function testReadMissingFileThrows(): void
    {
        $adapter = $this->adapter();

        try {
            $adapter->read('missing.txt');
            self::fail('Expected UnableToReadFile');
        } catch (UnableToReadFile) {
            self::addToAssertionCount(1);
        }
    }

    public function testReadStreamRewinds(): void
    {
        $adapter = $this->adapter();
        $adapter->write('rewind.txt', 'abc', new Config());
        $stream = $adapter->readStream('rewind.txt');

        self::assertIsResource($stream);
        self::assertSame(0, ftell($stream));
        self::assertSame('abc', stream_get_contents($stream));
        fclose($stream);
    }

    public function testFileExistsTrueAndFalse(): void
    {
        $adapter = $this->adapter();
        $adapter->write('yes.txt', '1', new Config());

        self::assertTrue($adapter->fileExists('yes.txt'));
        self::assertFalse($adapter->fileExists('no.txt'));
    }

    public function testFileExistsWrapsProviderFailure(): void
    {
        $adapter = new SftpAdapter(
            new CallbackConnectionProvider(static fn () => throw new RuntimeException('boom')),
            '/upload'
        );

        try {
            $adapter->fileExists('x');
            self::fail('Expected UnableToCheckFileExistence');
        } catch (UnableToCheckFileExistence $exception) {
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
    }

    public function testDirectoryExistsUsesATrailingSlashAndFileExistsDoesNot(): void
    {
        $adapter = $this->adapter();
        $this->sftp->seedDirectory('/upload/sub/');

        $adapter->directoryExists('sub');
        $adapter->fileExists('sub');

        $isDirPath = $this->findLastCallArg('is_dir');
        $isFilePath = $this->findLastCallArg('is_file');

        self::assertSame('/upload/sub/', $isDirPath);
        self::assertSame('/upload/sub', $isFilePath);
    }

    public function testDirectoryExistsWrapsProviderFailure(): void
    {
        $adapter = new SftpAdapter(
            new CallbackConnectionProvider(static fn () => throw new RuntimeException('boom')),
            '/upload'
        );

        try {
            $adapter->directoryExists('sub');
            self::fail('Expected UnableToCheckDirectoryExistence');
        } catch (UnableToCheckDirectoryExistence $exception) {
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
    }

    public function testCreateDirectoryIsANoOpWhenPresent(): void
    {
        $this->sftp->seedDirectory('/upload/existing/');
        $adapter = $this->adapter();
        $before = $this->sftp->mkdirCallCount();
        $adapter->createDirectory('existing', new Config());
        self::assertSame($before, $this->sftp->mkdirCallCount());
    }

    public function testCreateDirectoryPrefersDirectoryVisibility(): void
    {
        $adapter = $this->adapter();
        $adapter->createDirectory('one', new Config([
            Config::OPTION_DIRECTORY_VISIBILITY => Visibility::PRIVATE,
            Config::OPTION_VISIBILITY => Visibility::PUBLIC,
        ]));
        self::assertSame(0701, $this->lastMkdirMode('/upload/one'));

        $adapter->createDirectory('two', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        self::assertSame(0751, $this->lastMkdirMode('/upload/two'));
    }

    public function testCreateDirectoryFailure(): void
    {
        $this->sftp->armMkdirFailure(false);
        $adapter = $this->adapter();

        try {
            $adapter->createDirectory('fail', new Config());
            self::fail('Expected UnableToCreateDirectory');
        } catch (UnableToCreateDirectory) {
            self::addToAssertionCount(1);
        }
    }

    public function testCreateDirectoryRaceIsSuccess(): void
    {
        $this->sftp->armMkdirFailure(true);
        $adapter = $this->adapter();
        $adapter->createDirectory('race', new Config());
        self::assertTrue($this->sftp->is_dir('/upload/race/'));
    }

    public function testSetVisibilityUsesTheFileMode(): void
    {
        $adapter = $this->adapter();
        $adapter->write('vis.txt', 'x', new Config());
        $adapter->setVisibility('vis.txt', Visibility::PUBLIC);
        self::assertSame(0641, $this->lastChmodMode('/upload/vis.txt'));

        $adapter->setVisibility('vis.txt', Visibility::PRIVATE);
        self::assertSame(0601, $this->lastChmodMode('/upload/vis.txt'));
    }

    public function testStatMetadataUsesMaskedPermissions(): void
    {
        $this->sftp->seedFile('/upload/meta.txt', str_repeat('a', 12345), 0100601);
        $adapter = $this->adapter();

        self::assertSame(12345, $adapter->fileSize('meta.txt')->fileSize());
        self::assertSame(1700000000, $adapter->lastModified('meta.txt')->lastModified());
        self::assertSame(Visibility::PRIVATE, $adapter->visibility('meta.txt')->visibility());
    }

    public function testMetadataOnADirectoryThrows(): void
    {
        $this->sftp->seedDirectory('/upload/only-dir/');
        $adapter = $this->adapter();

        try {
            $adapter->fileSize('only-dir');
            self::fail('Expected UnableToRetrieveMetadata');
        } catch (UnableToRetrieveMetadata $exception) {
            self::assertStringContainsString('path is not a file', $exception->getMessage());
        }
    }

    public function testMissingStatThrows(): void
    {
        $adapter = $this->adapter();

        try {
            $adapter->fileSize('missing');
            self::fail('Expected UnableToRetrieveMetadata');
        } catch (UnableToRetrieveMetadata) {
            self::addToAssertionCount(1);
        }
    }

    public function testMimeTypeReadsContentsThroughTheInjectedDetector(): void
    {
        $detector = new FixedMimeTypeDetector();
        $adapter = $this->adapter($detector);
        $adapter->write('mime.txt', 'body', new Config());
        self::assertSame('application/x-plan-test', $adapter->mimeType('mime.txt')->mimeType());

        $pathOnly = new FixedMimeTypeDetector(fromPath: 'text/x-from-path');
        $adapterPath = new SftpAdapter(
            new CallbackConnectionProvider(fn () => $this->sftp),
            '/upload',
            new FixedVisibilityConverter(),
            $pathOnly,
            true
        );
        self::assertSame('text/x-from-path', $adapterPath->mimeType('mime.txt')->mimeType());
    }

    public function testUnknownMimeTypeThrows(): void
    {
        $detector = new FixedMimeTypeDetector(fromContents: null);
        $adapter = $this->adapter($detector);
        $adapter->write('mime.txt', 'body', new Config());

        try {
            $adapter->mimeType('mime.txt');
            self::fail('Expected UnableToRetrieveMetadata');
        } catch (UnableToRetrieveMetadata $exception) {
            self::assertStringContainsString('Unknown.', $exception->getMessage());
        }
    }

    public function testMimeDetectorFailureIsWrapped(): void
    {
        $detector = new FixedMimeTypeDetector(throwOnDetect: true);
        $adapter = $this->adapter($detector);
        $adapter->write('mime.txt', 'body', new Config());

        try {
            $adapter->mimeType('mime.txt');
            self::fail('Expected UnableToRetrieveMetadata');
        } catch (UnableToRetrieveMetadata $exception) {
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
    }

    public function testListContentsSkipsDotEntriesAndStripsTheRoot(): void
    {
        $this->sftp->seedFile('/upload/sub/note.txt', 'x');
        $adapter = $this->adapter();
        $items = iterator_to_array($adapter->listContents('sub', false));

        self::assertCount(1, $items);
        self::assertInstanceOf(FileAttributes::class, $items[0]);
        self::assertSame('sub/note.txt', $items[0]->path());
    }

    public function testDeepListingRecursesAndShallowDoesNot(): void
    {
        $this->sftp->seedDirectory('/upload/nest/');
        $this->sftp->seedFile('/upload/nest/child.txt', 'c');
        $adapter = $this->adapter();

        $shallow = iterator_to_array($adapter->listContents('', false));
        self::assertCount(1, $shallow);
        self::assertInstanceOf(DirectoryAttributes::class, $shallow[0]);
        self::assertSame('nest', $shallow[0]->path());

        $deep = iterator_to_array($adapter->listContents('', true));
        self::assertCount(2, $deep);
        self::assertSame('nest/child.txt', $deep[1]->path());
    }

    public function testMissingListingIsEmpty(): void
    {
        $adapter = $this->adapter();
        $items = iterator_to_array($adapter->listContents('missing', false));
        self::assertSame([], $items);
    }

    public function testDeleteRemovesThePrefixedFile(): void
    {
        $adapter = $this->adapter();
        $adapter->write('note.txt', 'x', new Config());
        $adapter->delete('note.txt');
        self::assertFalse($adapter->fileExists('note.txt'));
    }

    public function testDeleteDirectoryRemovesTheTree(): void
    {
        $this->sftp->seedDirectory('/upload/dir/');
        $this->sftp->seedFile('/upload/dir/note.txt', 'x');
        $adapter = $this->adapter();
        $adapter->deleteDirectory('dir');
        self::assertFalse($this->sftp->is_dir('/upload/dir/'));
        self::assertFalse($adapter->fileExists('dir/note.txt'));
    }

    public function testMoveRenamesAndKeepsBytes(): void
    {
        $adapter = $this->adapter();
        $adapter->write('src.txt', 'payload', new Config());
        $adapter->move('src.txt', 'dst.txt', new Config());

        self::assertFalse($adapter->fileExists('src.txt'));
        self::assertSame('payload', $adapter->read('dst.txt'));
    }

    public function testMoveOfTheSamePathDoesNotRename(): void
    {
        $adapter = $this->adapter();
        $adapter->write('same.txt', 'payload', new Config());
        $before = $this->sftp->renameCallCount();
        $adapter->move('same.txt', 'same.txt', new Config());
        self::assertSame($before, $this->sftp->renameCallCount());
        self::assertSame('payload', $adapter->read('same.txt'));
    }

    public function testMoveOverwritesAnExistingFile(): void
    {
        $adapter = $this->adapter();
        $adapter->write('src.txt', 'new-body', new Config());
        $adapter->write('dst.txt', 'old', new Config());
        $this->sftp->armRenameFailOnce('/upload/dst.txt');
        $adapter->move('src.txt', 'dst.txt', new Config());
        self::assertSame('new-body', $adapter->read('dst.txt'));
    }

    public function testMoveFailureThrows(): void
    {
        $adapter = $this->adapter();
        $adapter->write('src.txt', 'x', new Config());
        $this->sftp->armRenameFailOnce('/upload/dst.txt');
        $this->sftp->armRenameFailOnce('/upload/dst.txt');

        try {
            $adapter->move('src.txt', 'dst.txt', new Config());
            self::fail('Expected UnableToMoveFile');
        } catch (UnableToMoveFile) {
            self::addToAssertionCount(1);
        }
    }

    public function testMoveWrapsAParentDirectoryFailure(): void
    {
        $this->sftp->armMkdirFailure(false);
        $adapter = $this->adapter();
        $adapter->write('src.txt', 'x', new Config());

        try {
            $adapter->move('src.txt', 'missing/child.txt', new Config());
            self::fail('Expected UnableToMoveFile');
        } catch (UnableToMoveFile $exception) {
            self::assertInstanceOf(UnableToCreateDirectory::class, $exception->getPrevious());
        }
    }

    public function testCopyKeepsBytesAndVisibility(): void
    {
        $adapter = $this->adapter();
        $adapter->write('src.txt', 'payload', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $adapter->copy('src.txt', 'dst.txt', new Config());
        self::assertSame('payload', $adapter->read('dst.txt'));
        self::assertSame(Visibility::PRIVATE, $adapter->visibility('dst.txt')->visibility());
    }

    public function testCopyCanDeclineRetainedVisibility(): void
    {
        $adapter = $this->adapter();
        $adapter->write('src.txt', 'payload', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $adapter->copy('src.txt', 'dst.txt', new Config([Config::OPTION_RETAIN_VISIBILITY => false]));
        self::assertSame('payload', $adapter->read('dst.txt'));
    }

    public function testCopyExplicitVisibilityWins(): void
    {
        $adapter = $this->adapter();
        $adapter->write('src.txt', 'payload', new Config([Config::OPTION_VISIBILITY => Visibility::PRIVATE]));
        $adapter->copy('src.txt', 'dst.txt', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        self::assertSame(Visibility::PUBLIC, $adapter->visibility('dst.txt')->visibility());
    }

    public function testCopyOfAMissingFileThrows(): void
    {
        $adapter = $this->adapter();

        try {
            $adapter->copy('missing.txt', 'dst.txt', new Config());
            self::fail('Expected UnableToCopyFile');
        } catch (UnableToCopyFile $exception) {
            self::assertInstanceOf(UnableToReadFile::class, $exception->getPrevious());
        }
    }

    public function testDisconnectForwardsAndClearsTheConnection(): void
    {
        $adapter = $this->adapter();
        $adapter->fileExists('x');
        $adapter->disconnect();
        self::assertFalse($this->sftp->isConnected());
    }

    public function testDestructorDisconnectsOnlyWhenAsked(): void
    {
        $withFlag = function (bool $flag): bool {
            $sftp = new RecordingSftp();
            $sftp->seedDirectory('/upload/');
            $provider = new CallbackConnectionProvider(fn () => $sftp);
            $adapter = new SftpAdapter($provider, '/upload', disconnectOnDestruct: $flag);
            $adapter->fileExists('x');
            unset($adapter);

            return $sftp->isConnected();
        };

        self::assertFalse($withFlag(true));
        self::assertTrue($withFlag(false));
    }

    private function adapter(?FixedMimeTypeDetector $detector = null): SftpAdapter
    {
        return new SftpAdapter(
            new CallbackConnectionProvider(fn () => $this->sftp),
            '/upload',
            new FixedVisibilityConverter(),
            $detector ?? new FixedMimeTypeDetector()
        );
    }

    private function lastChmodMode(string $path): ?int
    {
        foreach (array_reverse($this->sftp->calls) as $call) {
            if ($call[0] === 'chmod' && $call[1][1] === $path) {
                return (int) $call[1][0];
            }
        }

        return null;
    }

    private function lastMkdirMode(string $path): ?int
    {
        foreach (array_reverse($this->sftp->calls) as $call) {
            if ($call[0] === 'mkdir' && $this->normalize($call[1][0]) === $this->normalize($path)) {
                return (int) $call[1][1];
            }
        }

        return null;
    }

    private function findLastCallArg(string $method): mixed
    {
        foreach (array_reverse($this->sftp->calls) as $call) {
            if ($call[0] === $method) {
                return $call[1][0];
            }
        }

        return null;
    }

    private function normalize(string $path): string
    {
        return rtrim('/' . ltrim($path, '/'), '/') . '/';
    }
}
