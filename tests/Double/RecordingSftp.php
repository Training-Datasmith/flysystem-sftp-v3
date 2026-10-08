<?php

declare(strict_types=1);

namespace League\Flysystem\PhpseclibV3\Tests\Double;

use phpseclib3\Net\SFTP;

class RecordingSftp extends SFTP
{
    /** @var array<string, string> */
    private array $files = [];

    /** @var array<string, true> */
    private array $directories = [];

    /** @var array<string, int> */
    private array $modes = [];

    private bool $connected = true;

    /** @var array<string, true> */
    private array $chmodFailures = [];

    /** @var array<string, true> */
    private array $putFailures = [];

    private bool $mkdirShouldFail = false;

    private bool $mkdirThenExists = false;

    private ?string $renameFailOnceDestination = null;

    /** @var list<array{0: string, 1: array<int, mixed>}> */
    public array $calls = [];

    /** @var array<string, array<int|string, array<string, mixed>>> */
    private array $rawlistExtras = [];

    public function __construct()
    {
        parent::__construct(fopen('php://temp', 'w+'));
    }

    public function seedDirectory(string $path): void
    {
        $this->directories[$this->normalizeDir($path)] = true;
    }

    public function seedFile(string $path, string $contents, int $mode = 0100644): void
    {
        $this->files[$path] = $contents;
        $this->modes[$path] = $mode;
        $parent = dirname($path);
        if ($parent !== '/' && $parent !== '') {
            $this->seedDirectory($parent . '/');
        }
    }

    public function failOnChmod(string $path): void
    {
        $this->chmodFailures[$path] = true;
    }

    public function failOnPut(string $path): void
    {
        $this->putFailures[$path] = true;
    }

    public function armMkdirFailure(bool $thenExists = false): void
    {
        $this->mkdirShouldFail = true;
        $this->mkdirThenExists = $thenExists;
    }

    public function armRenameFailOnce(string $destination): void
    {
        $this->renameFailOnceDestination = $destination;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function seedRawlistEntryWithNumericKey(string $parentDir, int $key, array $attributes): void
    {
        $dir = $this->normalizeDir($parentDir);
        $this->rawlistExtras[$dir][$key] = $attributes;
    }

    public function renameCallCount(): int
    {
        $count = 0;
        foreach ($this->calls as $call) {
            if ($call[0] === 'rename') {
                ++$count;
            }
        }

        return $count;
    }

    public function mkdirCallCount(): int
    {
        $count = 0;
        foreach ($this->calls as $call) {
            if ($call[0] === 'mkdir') {
                ++$count;
            }
        }

        return $count;
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function isConnected($level = 0): bool
    {
        return $this->connected;
    }

    public function ping()
    {
        return true;
    }

    /**
     * @param int $mode
     * @param string $filename
     * @param bool $recursive
     *
     * @return bool
     */
    public function chmod($mode, $filename, $recursive = false)
    {
        $this->record('chmod', $mode, $filename, $recursive);

        if (isset($this->chmodFailures[$filename])) {
            unset($this->chmodFailures[$filename]);

            return false;
        }

        $this->modes[$filename] = (int) $mode;

        return true;
    }

    /**
     * @param string          $remote_file
     * @param resource|string $data
     * @param int             $mode
     * @param int             $start
     * @param int             $local_start
     * @param null            $progressCallback
     *
     * @return bool
     */
    public function put(
        $remote_file,
        $data,
        $mode = self::SOURCE_STRING,
        $start = -1,
        $local_start = -1,
        $progressCallback = null
    ) {
        $this->record('put', $remote_file, $data, $mode, $start, $local_start, $progressCallback);

        if (isset($this->putFailures[$remote_file])) {
            return false;
        }

        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        $this->files[$remote_file] = (string) $data;

        return true;
    }

    /**
     * @param string               $remote_file
     * @param string|bool|resource $local_file
     * @param int                  $offset
     * @param int                  $length
     * @param callable|null        $progressCallback
     *
     * @return string|bool
     */
    public function get($remote_file, $local_file = false, $offset = 0, $length = -1, $progressCallback = null)
    {
        $this->record('get', $remote_file, $local_file, $offset, $length, $progressCallback);

        if (! isset($this->files[$remote_file])) {
            return false;
        }

        $contents = $this->files[$remote_file];

        if (is_resource($local_file)) {
            fwrite($local_file, $contents);

            return true;
        }

        if ($local_file === false) {
            return $contents;
        }

        return false;
    }

    /**
     * @param string $dir
     * @param int    $mode
     * @param bool   $recursive
     *
     * @return bool
     */
    public function mkdir($dir, $mode = -1, $recursive = false)
    {
        $this->record('mkdir', $dir, $mode, $recursive);

        if ($this->mkdirShouldFail) {
            $this->mkdirShouldFail = false;
            if ($this->mkdirThenExists) {
                $this->directories[$this->normalizeDir($dir)] = true;

                return false;
            }

            return false;
        }

        $this->directories[$this->normalizeDir($dir)] = true;
        $this->modes[$this->normalizeDir($dir)] = $mode === -1 ? 0755 : (int) $mode;

        return true;
    }

    /**
     * @param string $dir
     *
     * @return bool
     */
    public function rmdir($dir)
    {
        $this->record('rmdir', $dir);
        unset($this->directories[$this->normalizeDir($dir)]);

        return true;
    }

    /**
     * @param string $path
     * @param bool   $recursive
     *
     * @return bool
     */
    public function delete($path, $recursive = true)
    {
        $this->record('delete', $path, $recursive);

        if (str_ends_with((string) $path, '/')) {
            $prefix = rtrim((string) $path, '/') . '/';
            foreach (array_keys($this->files) as $file) {
                if (str_starts_with($file, $prefix)) {
                    unset($this->files[$file]);
                }
            }
            foreach (array_keys($this->directories) as $directory) {
                if (str_starts_with($directory, $prefix)) {
                    unset($this->directories[$directory]);
                }
            }

            return true;
        }

        unset($this->files[$path]);

        return true;
    }

    /**
     * @param string $filename
     *
     * @return array<string, mixed>|false
     */
    public function stat($filename)
    {
        $this->record('stat', $filename);

        if (isset($this->files[$filename])) {
            return [
                'size' => strlen($this->files[$filename]),
                'mode' => $this->modes[$filename] ?? 0100644,
                'mtime' => 1700000000,
                'type' => NET_SFTP_TYPE_REGULAR,
            ];
        }

        $dir = $this->normalizeDir($filename);
        if (isset($this->directories[$dir])) {
            return [
                'size' => 0,
                'mode' => $this->modes[$dir] ?? 0040755,
                'mtime' => 1700000000,
                'type' => NET_SFTP_TYPE_DIRECTORY,
            ];
        }

        return false;
    }

    /**
     * @param string $dir
     * @param bool   $recursive
     *
     * @return array<int|string, mixed>|false
     */
    public function rawlist($dir = '.', $recursive = false)
    {
        $this->record('rawlist', $dir, $recursive);

        if ($recursive) {
            return false;
        }

        $dir = $this->normalizeDir($dir);
        if (! isset($this->directories[$dir])) {
            return false;
        }

        $listing = [
            '.' => ['type' => NET_SFTP_TYPE_DIRECTORY],
            '..' => ['type' => NET_SFTP_TYPE_DIRECTORY],
        ];

        $prefix = rtrim($dir, '/') . '/';

        foreach ($this->directories as $path => $_) {
            if ($path === $dir) {
                continue;
            }
            $parent = $this->normalizeDir(dirname(rtrim($path, '/')));
            if ($parent !== $dir) {
                continue;
            }
            $name = basename(rtrim($path, '/'));
            $listing[$name] = [
                'size' => 0,
                'mode' => $this->modes[$path] ?? 0040701,
                'mtime' => 1700000000,
                'type' => NET_SFTP_TYPE_DIRECTORY,
            ];
        }

        foreach ($this->files as $path => $contents) {
            $parent = $this->normalizeDir(dirname($path));
            if ($parent !== $dir) {
                continue;
            }
            $name = basename($path);
            $listing[$name] = [
                'size' => strlen($contents),
                'mode' => $this->modes[$path] ?? 0100644,
                'mtime' => 1700000000,
                'type' => NET_SFTP_TYPE_REGULAR,
            ];
        }

        if (isset($this->rawlistExtras[$dir])) {
            foreach ($this->rawlistExtras[$dir] as $name => $attributes) {
                $listing[$name] = $attributes;
            }
        }

        return $listing;
    }

    /**
     * @param string $oldname
     * @param string $newname
     *
     * @return bool
     */
    public function rename($oldname, $newname)
    {
        $this->record('rename', $oldname, $newname);

        if ($this->renameFailOnceDestination === $newname) {
            $this->renameFailOnceDestination = null;

            return false;
        }

        if (! isset($this->files[$oldname])) {
            return false;
        }

        $this->files[$newname] = $this->files[$oldname];
        if (isset($this->modes[$oldname])) {
            $this->modes[$newname] = $this->modes[$oldname];
        }
        unset($this->files[$oldname], $this->modes[$oldname]);

        return true;
    }

    public function is_dir($path): bool
    {
        $this->record('is_dir', $path);

        return isset($this->directories[$this->normalizeDir($path)]);
    }

    public function is_file($path): bool
    {
        $this->record('is_file', $path);

        return isset($this->files[$path]);
    }

    private function record(string $method, ...$args): void
    {
        $this->calls[] = [$method, $args];
    }

    private function normalizeDir(string $path): string
    {
        if ($path === '' || $path === '.') {
            return '/';
        }

        $path = '/' . ltrim(preg_replace('#/+#', '/', $path), '/');

        return str_ends_with($path, '/') ? $path : $path . '/';
    }
}
